#!/bin/sh
# cli/dresscode-hook.php given a file in cli/ spelled through a symlink, as /tmp is on macOS: a plain
# comparison takes it for a file outside cli/ and skips it with exit 0. Run by `make qa`.
set -eu

root=$(cd "$(dirname "$0")/../.." && pwd)
dir=$(mktemp -d)
probe=$root/cli/hook-probe-$$.php
trap 'rm -rf "$dir" "$probe"' EXIT
ln -s "$root" "$dir/link"
cat > "$probe" <<'PHP'
<?php
declare(strict_types=1);
$a = array();
PHP

printf '{"tool_input":{"file_path":"%s"}}' "$dir/link/cli/$(basename "$probe")" \
	| "$root/bin/frankenphp" php-cli "$root/cli/dresscode-hook.php" >/dev/null 2>&1 || true
if grep -q 'array()' "$probe"; then
	echo "dresscode-hook skipped a file in cli/ reached through a symlink" >&2
	exit 1
fi
echo "dresscode-hook ok"
