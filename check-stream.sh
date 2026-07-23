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

./vendor/frankenphp php-server --root app --listen "127.0.0.1:$PORT" --no-compress \
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
