#!/bin/sh
# Compiles adminer.php and editor.php from the extracted release zip, with the given patches
# applied. Unpatched, the output is byte-identical to the release's own files.
#
# Usage: cli/compile-adminer.sh <source dir> <output dir> <frankenphp binary> [patch...]
#
# Builds in <output dir>.tmp and moves it into place only at the end, so a patch that stops
# applying leaves no output dir, and make tries again instead of shipping an unpatched build.
set -eu

src=$1
out=$2
frankenphp=$(pwd)/$3
shift 3

rm -rf "$out" "$out.tmp"
cp -R "$src" "$out.tmp"
for p in "$@"; do
	patch -p1 -s -N -d "$out.tmp" < "$p"
done

# compile.php writes into the directory it runs in. drivers/ first: the plugin hashes compiled
# into adminer.php are read from there.
(
	cd "$out.tmp"
	mkdir drivers
	"$frankenphp" php-cli compile.php drivers
	"$frankenphp" php-cli compile.php
	"$frankenphp" php-cli compile.php editor
)

# Only the two files, so the tree (and its copy in the CI cache) stays the size of the output.
mkdir "$out"
mv "$out.tmp"/adminer-*.php "$out.tmp"/editor-*.php "$out"
rm -rf "$out.tmp"
