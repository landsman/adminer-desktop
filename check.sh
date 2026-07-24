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

# Enabling a plugin puts it in adminer-plugins/, so the filesystem is the state.
JAR=$(mktemp)
TOKEN=$(curl -s -c "$JAR" "$BASE/adminer.php" | grep -o "name='token' value='[^']*'" | head -1 | sed "s/.*value='//;s/'//")
curl -s -b "$JAR" -c "$JAR" -L -o /dev/null \
	-d "desktop_settings=1" -d "plugins[]=dark-switcher" -d "token=$TOKEN" "$BASE/adminer.php"
# -e not -L: windows cannot make symlinks without elevated rights, so enabling copies
# the file there instead.
[ -e app/adminer-plugins/dark-switcher.php ] || {
	echo "FAIL: ticking a plugin did not enable it"; rm -f "$JAR"; exit 1; }
# ...and unticking must remove it again, which is the half that silently rots.
TOKEN=$(curl -s -b "$JAR" -c "$JAR" "$BASE/adminer.php" | grep -o "name='token' value='[^']*'" | head -1 | sed "s/.*value='//;s/'//")
curl -s -b "$JAR" -c "$JAR" -L -o /dev/null \
	-d "desktop_settings=1" -d "token=$TOKEN" "$BASE/adminer.php"
rm -f "$JAR"
[ ! -e app/adminer-plugins/dark-switcher.php ] || {
	echo "FAIL: unticking a plugin did not disable it"; exit 1; }
echo "ok: plugins toggle on and off"

echo "PASS"
