#!/bin/sh
# Checks the desktop plugin's behaviour before login — the things a plugin can silently break
# and curl can still see: the prefilled Server field, the injected refresh shortcut, design
# switching, and the plugin toggle. Run against the real login page over a throwaway server
# with its own data dir.
set -e

PORT=${ADMINER_PORT:-18000}
export ADMINER_PORT="$PORT"
BASE="http://127.0.0.1:$PORT"

# Refuse to run against someone else's server — otherwise a stray process on the port
# makes this check test the wrong thing (it did, on port 8000: it was uvicorn).
if curl -s -o /dev/null --max-time 2 "$BASE/"; then
	echo "FAIL: something is already listening on $PORT. Set ADMINER_PORT to a free port."
	exit 1
fi

# Own the fixture, like tests/e2e does: the design and plugin preferences persist to
# settings.json in this dir, and without one the design asserts below silently no-op (set()
# has nowhere to write). The Go launcher sets this in the real app; here we make a throwaway.
ADMINER_DESKTOP_DATA=$(mktemp -d)
export ADMINER_DESKTOP_DATA

./bin/frankenphp php-server --root app --listen "127.0.0.1:$PORT" --no-compress \
	2>/tmp/adminer-desktop-check.log &
SERVER=$!
trap 'kill $SERVER 2>/dev/null; rm -rf "$ADMINER_DESKTOP_DATA"' EXIT

# Wait for the app to answer rather than sleeping a guessed amount.
i=0
while ! curl -sf -o /dev/null "$BASE/adminer.php"; do
	i=$((i + 1))
	[ $i -gt 50 ] && { echo "FAIL: server never came up"; cat /tmp/adminer-desktop-check.log; exit 1; }
	sleep 0.2
done

# The desktop plugin must prefill Server, or a Docker/remote database fails to connect with a
# confusing Unix-socket error. This is also the proof it is our app answering, not a stray
# process on the port: only our plugin rewrites the empty Server field to 127.0.0.1.
curl -s "$BASE/adminer.php" | grep -q 'name="auth\[server\]" value="127.0.0.1"' || {
	echo "FAIL: not our app on $PORT, or the Server field is not prefilled with 127.0.0.1"; exit 1; }
echo "ok: Server field prefilled"

# The refresh shortcut is a script we inject. curl cannot press F5 — that it reloads is
# browser behaviour — but it can prove the two things that silently regress: the tag is
# emitted with the CSP nonce it needs under strict-dynamic, and the file actually serves.
curl -s "$BASE/adminer.php" | grep -q "src/Assets/javascript/shortcuts.js?v=[0-9]*' nonce=" || {
	echo "FAIL: refresh-shortcut script not emitted with a nonce"; exit 1; }
[ "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/src/Assets/javascript/shortcuts.js")" = "200" ] || {
	echo "FAIL: shortcuts.js does not serve"; exit 1; }
echo "ok: refresh shortcut wired and served"

# version-noverify is on whatever the user picked: adminer otherwise fetches
# adminer.org/version/ from <body onload> and offers an upgrade this app cannot install.
curl -s "$BASE/adminer.php" | grep -q "verifyVersion = () => { }" || {
	echo "FAIL: the version check was not disabled"; exit 1; }
echo "ok: adminer's version check is off"

# Switching design must work before you log in. Upstream only handles it in
# afterConnect(), so without our override adminer answers "the action will be performed
# after successful login" and nothing changes.
JAR=$(mktemp)
OUT=/tmp/adminer-desktop-design.html
TOKEN=$(curl -s -c "$JAR" "$BASE/adminer.php" | grep -o "name='token' value='[^']*'" | head -1 | sed "s/.*value='//;s/'//")
curl -s -b "$JAR" -c "$JAR" -L -o "$OUT" \
	-d "desktop_settings=1" -d "design_light=src/Settings/Theme/designs/brade/adminer.css" \
	-d "design_dark=src/Settings/Theme/designs/dracula/adminer-dark.css" \
	-d "token=$TOKEN" "$BASE/adminer.php"
rm -f "$JAR"
# Both must come back media-gated, which is what makes the OS theme pick the design.
grep -q "media='(prefers-color-scheme: light)' href='src/Settings/Theme/designs/brade/adminer.css'" "$OUT" || {
	echo "FAIL: light design not applied, or not gated on prefers-color-scheme"
	# Say why. Chasing this blind across CI runs costs more than printing it here does.
	echo "--- php diagnostics from the response:"
	grep -oiE '(warning|notice|fatal error|parse error)[^<]{0,120}' "$OUT" | head -5 || true
	echo "--- design options offered:"
	grep -o "value=\"designs/[^\"]*\"" "$OUT" | head -3 || echo "  (none — glob found no designs)"
	echo "--- stylesheets emitted:"
	grep -o "<link rel='stylesheet'[^>]*>" "$OUT" | head -5 || true
	echo "--- server log:"
	tail -5 /tmp/adminer-desktop-check.log
	exit 1
}
grep -q "media='(prefers-color-scheme: dark)' href='src/Settings/Theme/designs/dracula/adminer-dark.css'" "$OUT" || {
	echo "FAIL: dark design not applied, or not gated on prefers-color-scheme"; exit 1; }
echo "ok: designs switch before login and follow the OS theme"

# The enabled set is stored in settings.json, so the file is the state.
SETTINGS="$ADMINER_DESKTOP_DATA/settings.json"
JAR=$(mktemp)
TOKEN=$(curl -s -c "$JAR" "$BASE/adminer.php" | grep -o "name='token' value='[^']*'" | head -1 | sed "s/.*value='//;s/'//")
curl -s -b "$JAR" -c "$JAR" -L -o /dev/null \
	-d "desktop_settings=1" -d "plugins[]=row-numbers" -d "token=$TOKEN" "$BASE/adminer.php"
grep -q '"row-numbers": *true' "$SETTINGS" || {
	echo "FAIL: ticking a plugin did not enable it"; rm -f "$JAR"; exit 1; }
# That form left the default-on plugins unticked, so the answer stored for them is "off" —
# which is the whole point of storing both answers rather than a list of the ones turned on.
grep -q '"tables-filter": *false' "$SETTINGS" || {
	echo "FAIL: unticking a default-on plugin was not stored"; rm -f "$JAR"; exit 1; }
# ...and unticking must remove it again, which is the half that silently rots: an empty
# form posts no plugins[] at all, and that is what has to clear the list.
TOKEN=$(curl -s -b "$JAR" -c "$JAR" "$BASE/adminer.php" | grep -o "name='token' value='[^']*'" | head -1 | sed "s/.*value='//;s/'//")
curl -s -b "$JAR" -c "$JAR" -L -o /dev/null \
	-d "desktop_settings=1" -d "token=$TOKEN" "$BASE/adminer.php"
rm -f "$JAR"
if grep -q '"row-numbers": *true' "$SETTINGS"; then
	echo "FAIL: unticking a plugin did not disable it"; exit 1
fi
echo "ok: plugins toggle on and off"

# Both answers are stored, so that shipping a plugin on by default cannot switch it back on
# for someone who turned it off. Three shapes, one page load each: the plugin's own script is
# the evidence, since it only reaches the page when adminer was handed the instance.
plugin_on() { # 1: what to store, 2: does before-unload have to be on
	printf '%s' "$1" > "$SETTINGS"
	if curl -s "$BASE/adminer.php" | grep -q "editChanged = true"; then ON=yes; else ON=no; fi
	[ "$ON" = "$2" ] || { echo "FAIL: stored $1 -> plugin on: $ON, expected $2"; exit 1; }
}
plugin_on '{"plugins":{"before-unload":true}}' yes
plugin_on '{"plugins":{"before-unload":false}}' no
# The shape this key had before it could say "off": a bare list of the ones turned on. Still
# read, so an upgrade does not silently disable what the user had enabled.
plugin_on '{"plugins":["before-unload"]}' yes
echo "ok: an answer of \"off\" is stored, and the older list shape still reads"

# Every plugin we ship, one at a time: enabled, the app still has to render. This is the
# check that the catalogue is a list of things that actually work — a plugin needing
# constructor arguments, a class name that a new adminer release renamed, or a second copy
# of an already-included class all show up here as a broken page and nowhere else.
# Settings are written straight to the file: the POST path is proven above, and 20-odd
# CSRF round trips to prove it again would only make this slow. Every other plugin is
# answered "off" in the same write, defaults included, so a failure names the one plugin
# that caused it instead of whatever it was loaded beside.
PLUGINS=/tmp/adminer-desktop-plugins.tsv
# shellcheck disable=SC2016  # the $names below are PHP's, and must not expand here
./bin/frankenphp php-cli -r 'require "app/vendor/autoload.php";
	$class = new ReflectionClass(Desktop\Settings\Plugins\PluginList::class);
	// Constructor arguments are keyed by class name, and a key naming a class we do not ship
	// is not an error — it simply never matches, and the plugin quietly goes back to the
	// upstream default the argument was there to override.
	$shipped = array_merge($class->getConstant("PICKED"), $class->getConstant("ALWAYS"));
	foreach (array_keys($class->getConstant("ARGUMENTS")) as $configured) {
		if (!in_array($configured, $shipped, true)) {
			fwrite(STDERR, "FAIL: ARGUMENTS configures $configured, which is not a plugin we ship\n");
			exit(1);
		}
	}
	$names = Desktop\Settings\Plugins\PluginList::names();
	foreach ($names as $name) {
		// The name is derived from the class, and a file that does not answer to it would be
		// skipped rather than fail — the plugin would simply stop being there, and every
		// assertion below would still pass.
		if (!is_file("app/src/Settings/Plugins/available/$name.php")) {
			fwrite(STDERR, "FAIL: no available/$name.php to load that plugin from\n");
			exit(1);
		}
		$answers = array_fill_keys($names, false);
		$answers[$name] = true;
		echo $name, "\t", json_encode(["plugins" => $answers]), "\n";
	}' > "$PLUGINS"
while IFS="$(printf '\t')" read -r NAME JSON; do
	printf '%s' "$JSON" > "$SETTINGS"
	OUT=/tmp/adminer-desktop-plugin.html
	CODE=$(curl -s -o "$OUT" -w '%{http_code}' "$BASE/adminer.php")
	[ "$CODE" = "200" ] || { echo "FAIL: $NAME -> HTTP $CODE"; exit 1; }
	# The login form still there, and nothing PHP had to say about it.
	grep -q 'name="auth\[server\]"' "$OUT" || {
		echo "FAIL: $NAME broke the login page"; grep -oiE '(fatal|parse) error[^<]{0,160}' "$OUT" | head -2; exit 1; }
	if grep -qiE '<b>(Fatal error|Parse error|Warning|Deprecated|Notice)</b>' "$OUT"; then
		echo "FAIL: $NAME raised a PHP error"
		grep -oiE '<b>(fatal error|parse error|warning|deprecated|notice)</b>[^<]{0,160}' "$OUT" | head -2
		exit 1
	fi
	# Adminer's answer when it cannot construct a plugin itself: it prints this instead.
	if grep -q "adminer-plugins.php</b>" "$OUT"; then
		echo "FAIL: $NAME cannot be constructed without arguments"; exit 1
	fi
	# ...and one that proves the enabled plugin was actually handed to adminer, not just
	# stored: a page that renders because nothing was loaded would pass everything above.
	if [ "$NAME" = "before-unload" ] && ! grep -q "editChanged = true" "$OUT"; then
		echo "FAIL: $NAME was enabled but its script is not on the page"; exit 1
	fi
done < "$PLUGINS"
rm -f "$SETTINGS"
echo "ok: every shipped plugin boots ($(wc -l < "$PLUGINS" | tr -d ' ') of them)"
rm -f "$PLUGINS"

# The AI-access panel offers one register command per agent, not just Claude Code's. A
# hardcoded `claude` is exactly the regression this catches, and it is drawn on the login page
# so curl can see it. The toast stylesheet is checked here for the same reason it once
# shipped unstyled: desktop.css only @imports it, so nothing else would notice it missing.
PANEL=/tmp/adminer-desktop-panel.html
curl -s "$BASE/adminer.php" > "$PANEL"
for AGENT in claude codex gemini; do
	grep -q "$AGENT mcp add adminer-desktop" "$PANEL" || {
		echo "FAIL: no register command for $AGENT in the AI-access panel"; exit 1; }
done
grep -q '@import "toast.css"' app/src/Assets/css/desktop.css || {
	echo "FAIL: toast.css is not imported, so toasts render unstyled"; exit 1; }
rm -f "$PANEL"
echo "ok: every agent's register command is offered, and the toast styles are loaded"

echo "PASS"
