#!/bin/sh
# Every way adminer starts a download, through the launcher's own download code against the
# real engine: WKWebView on macOS, WebKitGTK on Linux. Pages from export.php, served by the
# bundled frankenphp; each case's harness prints one line, and the saved file is checked too.
# Windows is skipped: WebView2 downloads on its own, there is no code of ours to test.
set -eu
cd "$(dirname "$0")/../.."

PORT=${DOWNLOADS_PORT:-18010}
BASE="http://127.0.0.1:$PORT/export.php"
if curl -s -o /dev/null --max-time 2 "$BASE"; then
	echo "FAIL: something is already listening on $PORT. Set DOWNLOADS_PORT to a free port."
	exit 1
fi

TMP=$(mktemp -d)
./bin/frankenphp php-server --root tests/launcher --listen "127.0.0.1:$PORT" --no-compress 2>"$TMP/server.log" &
SERVER=$!
trap 'kill $SERVER 2>/dev/null; rm -rf "$TMP"' EXIT

RUN=""
case $(uname -s) in
Darwin)
	clang -x objective-c -Ilauncher -framework Cocoa -framework WebKit \
		tests/launcher/downloads_darwin.m launcher/download_darwin.m -o "$TMP/test"
	;;
Linux)
	# shellcheck disable=SC2046 # pkg-config's flags are meant to split
	cc -Ilauncher tests/launcher/downloads_linux.c launcher/download_linux.c -o "$TMP/test" \
		$(pkg-config --cflags --libs gtk+-3.0 webkit2gtk-4.1)
	if [ -z "${DISPLAY:-}${WAYLAND_DISPLAY:-}" ]; then
		command -v xvfb-run >/dev/null || { echo "FAIL: no display and no xvfb-run; install xvfb"; exit 1; }
		RUN="xvfb-run -a"
	fi
	;;
*)
	echo "launcher downloads skipped on $(uname -s)"
	exit 0
	;;
esac

i=0
while ! curl -sf -o /dev/null "$BASE?case=text"; do
	i=$((i + 1))
	[ $i -gt 50 ] && { echo "FAIL: fixture server never came up"; cat "$TMP/server.log"; exit 1; }
	sleep 0.2
done

# <case> <what the harness must print>; the downloaded file lands at $TMP/<case>. Its line is
# the last: Mesa prints its EGL warnings to stdout under Xvfb.
expect() {
	got=$($RUN "$TMP/test" "$BASE?case=$1" "$TMP/$1" 2>"$TMP/$1.log" | tail -n 1)
	[ "$got" = "$2" ] || { echo "FAIL: launcher downloads, $1: expected '$2', got '$got'"; cat "$TMP/$1.log"; exit 1; }
}

expect link "saved sql.csv" # the SQL command's Export > CSV, built in JS
printf 'a,b\r\n1,2\r\n' | cmp -s - "$TMP/link" || { echo "FAIL: launcher downloads, link: wrong content"; exit 1; }
expect file "saved sql.sql"
printf 'SELECT 1;\n' | cmp -s - "$TMP/file" || { echo "FAIL: launcher downloads, file: wrong content"; exit 1; }
expect gz "saved sql.sql.gz"
gzip -dc "$TMP/gz" | grep -qx 'SELECT 1;' || { echo "FAIL: launcher downloads, gz: not the gzipped dump"; exit 1; }
expect text "page SELECT 1;" # output "open" shows the dump in the window
if [ "$(uname -s)" = Darwin ]; then
	expect mailto "opened mailto:someone@example.com" # a mail link in a result cell still opens Mail
fi
[ ! -e "$TMP/link.part" ] && [ ! -e "$TMP/file.part" ] || { echo "FAIL: launcher downloads: a .part was left behind"; exit 1; }
echo "launcher downloads ok"
