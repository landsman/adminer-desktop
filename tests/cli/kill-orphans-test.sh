#!/bin/sh
# What cli/kill-orphans.sh would stop, given `ps` lines: an orphan from the installed app, whose
# path has a space in it, and one from a checkout — not a server with its window still open, not
# another program on ppid 1, not a php-cli. Run by `make qa`.
set -eu

got=$(sh "$(dirname "$0")/../../cli/kill-orphans.sh" --filter <<'EOF'
  101     1 /Applications/Adminer Desktop.app/Contents/MacOS/frankenphp php-server --root /Applications/Adminer Desktop.app/Contents/Resources/app --listen 127.0.0.1:63864
  102     1 bin/frankenphp php-server --root app --listen 127.0.0.1:55548 --no-compress
  103  4242 bin/frankenphp php-server --root app --listen 127.0.0.1:55733 --no-compress
  104     1 /usr/sbin/cupsd -l
  105     1 bin/frankenphp php-cli tests/e2e/harness/slow-server.php 55999 1
EOF
)
want=$(printf '101\n102')
if [ "$got" != "$want" ]; then
	echo "kill-orphans picked [$got], wanted [$want]" >&2
	exit 1
fi
echo "kill-orphans ok"
