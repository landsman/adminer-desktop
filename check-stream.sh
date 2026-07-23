#!/bin/sh
# M0: the only thing that can invalidate this whole project.
#
# Asserts the transport does not (a) time out and (b) buffer. Both would break adminer's
# long dumps and progressive SQL output. Passing this means every adminer long-op path is
# covered, because they all go through the same ob_flush()/flush() mechanism.
#
# Usage: ./check-stream.sh [seconds-per-line]  (default 5 -> ~120s total)
set -e

S=${1:-5}
N=24
TOTAL=$((S * N))
PORT=${ADMINER_PORT:-18000}
export ADMINER_PORT=$PORT
BASE="http://127.0.0.1:$PORT"
URL="$BASE/_stream.php?n=$N&s=$S"

# Refuse to run against someone else's server — otherwise a stray process on the port
# makes this check test the wrong thing (it did, on port 8000: it was uvicorn).
if curl -s -o /dev/null --max-time 2 "$BASE/"; then
	echo "FAIL: something is already listening on $PORT. Set ADMINER_PORT to a free port."
	exit 1
fi

./bin/frankenphp php-server --root app --listen "127.0.0.1:$PORT" --no-compress \
	2>/tmp/adminer-desktop-check.log &
SERVER=$!
trap 'kill $SERVER 2>/dev/null' EXIT

# Wait for listen rather than sleeping a guessed amount.
i=0
while ! curl -sf -o /dev/null "$BASE/_stream.php?n=1&s=0"; do
	i=$((i + 1))
	[ $i -gt 50 ] && { echo "FAIL: server never came up"; cat /tmp/adminer-desktop-check.log; exit 1; }
	sleep 0.2
done
# Prove we are talking to our own PHP and not an impostor that happened to answer.
# Body, not headers: it is the thing only _stream.php can produce.
[ "$(curl -s "$BASE/_stream.php?n=1&s=0")" = "0" ] || {
	echo "FAIL: $PORT answered, but not with our _stream.php output"; exit 1; }

# The desktop plugin must prefill Server, or a Docker/remote database fails to connect
# with a confusing Unix-socket error. Checked end to end against the real login page.
curl -s "$BASE/adminer.php" | grep -q 'name="auth\[server\]" value="127.0.0.1"' || {
	echo "FAIL: login form does not prefill Server with 127.0.0.1"; exit 1; }
echo "ok: Server field prefilled"

# Switching design must work before you log in. Upstream only handles it in
# afterConnect(), so without our override adminer answers "the action will be performed
# after successful login" and nothing changes.
JAR=$(mktemp)
OUT=/tmp/adminer-desktop-design.html
TOKEN=$(curl -s -c "$JAR" "$BASE/adminer.php" | grep -o "name='token' value='[^']*'" | head -1 | sed "s/.*value='//;s/'//")
curl -s -b "$JAR" -c "$JAR" -L -o "$OUT" \
	-d "desktop_settings=1" -d "design_light=designs/brade/adminer.css" \
	-d "design_dark=designs/dracula/adminer-dark.css" \
	-d "token=$TOKEN" "$BASE/adminer.php"
rm -f "$JAR"
# Both must come back media-gated, which is what makes the OS theme pick the design.
grep -q "media='(prefers-color-scheme: light)' href='designs/brade/adminer.css'" "$OUT" || {
	echo "FAIL: light design not applied, or not gated on prefers-color-scheme"
	# Say why. Chasing this blind across CI runs costs more than printing it here does.
	echo "--- php diagnostics from the response:"
	grep -oiE '(warning|notice|fatal error|parse error)[^<]{0,120}' "$OUT" | head -5 || true
	echo "--- design options offered:"
	grep -o "value=\"designs/[^\"]*\"" "$OUT" | head -3 || echo "  (none — glob found no designs)"
	echo "--- stylesheets emitted:"
	grep -o "<link rel='stylesheet'[^>]*>" "$OUT" | head -5 || true
	echo "--- what the app sees on disk:"
	curl -s "$BASE/_stream.php?probe=1"
	echo "--- server log:"
	tail -5 /tmp/adminer-desktop-check.log
	exit 1
}
grep -q "media='(prefers-color-scheme: dark)' href='designs/dracula/adminer-dark.css'" "$OUT" || {
	echo "FAIL: dark design not applied, or not gated on prefers-color-scheme"; exit 1; }
echo "ok: designs switch before login and follow the OS theme"

# Enabling a plugin is a symlink into adminer-plugins/, so the filesystem is the state.
JAR=$(mktemp)
TOKEN=$(curl -s -c "$JAR" "$BASE/adminer.php" | grep -o "name='token' value='[^']*'" | head -1 | sed "s/.*value='//;s/'//")
curl -s -b "$JAR" -c "$JAR" -L -o /dev/null \
	-d "desktop_settings=1" -d "plugins[]=dark-switcher" -d "token=$TOKEN" "$BASE/adminer.php"
[ -L app/adminer-plugins/dark-switcher.php ] || {
	echo "FAIL: ticking a plugin did not create the symlink"; rm -f "$JAR"; exit 1; }
# ...and unticking must remove it again, which is the half that silently rots.
TOKEN=$(curl -s -b "$JAR" -c "$JAR" "$BASE/adminer.php" | grep -o "name='token' value='[^']*'" | head -1 | sed "s/.*value='//;s/'//")
curl -s -b "$JAR" -c "$JAR" -L -o /dev/null \
	-d "desktop_settings=1" -d "token=$TOKEN" "$BASE/adminer.php"
rm -f "$JAR"
[ ! -e app/adminer-plugins/dark-switcher.php ] || {
	echo "FAIL: unticking a plugin did not remove the symlink"; exit 1; }
echo "ok: plugins toggle on and off"

echo "streaming $N lines over ~${TOTAL}s ..."
START=$(date +%s)
FIRST=""
LINES=0
# -N disables curl's own buffering so arrival times are real.
curl -sN --max-time $((TOTAL + 60)) "$URL" | while read -r line; do
	NOW=$(date +%s)
	[ -z "$FIRST" ] && FIRST=$((NOW - START)) && echo "  first byte after ${FIRST}s"
	LINES=$((LINES + 1))
	echo "  line $line at $((NOW - START))s"
done > /tmp/adminer-desktop-check.out

ELAPSED=$(($(date +%s) - START))
GOT=$(grep -c '^  line ' /tmp/adminer-desktop-check.out || true)
FIRST_AT=$(sed -n 's/^  first byte after \([0-9]*\)s/\1/p' /tmp/adminer-desktop-check.out)

echo "got $GOT/$N lines in ${ELAPSED}s, first byte at ${FIRST_AT:-?}s"

[ "$GOT" -eq "$N" ] || { echo "FAIL: response truncated — transport timed out"; exit 1; }
[ "$ELAPSED" -ge $((TOTAL - S)) ] || { echo "FAIL: finished too fast, php didn't actually sleep"; exit 1; }
# The buffering assert: if Caddy held the whole body, byte one arrives only at the end.
[ "${FIRST_AT:-999}" -le $((S * 2)) ] || { echo "FAIL: buffered — first byte at ${FIRST_AT}s, expected <= $((S * 2))s"; exit 1; }

echo "PASS: no timeout, no buffering"
