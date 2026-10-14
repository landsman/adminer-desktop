#!/bin/sh
# Builds app/adminer.php and app/editor.php from the adminer sources composer installed, with
# patches/ applied by cweagans/composer-patches. Unpatched, the output is byte-identical to the
# release's own files, which is what checksums.txt was first recorded against.
#
# Usage: cli/compile-adminer.sh <adminer version> <frankenphp binary>
set -eu

version=$1
frankenphp=$2

# The three submodules the release is compiled with. Composer's download is GitHub's zipball,
# which leaves submodules out, so they come from git at the commit the tag points them at:
#   git ls-tree v<version> adminer/static/jush conf/JsShrink conf/PhpShrink
# Fetched by commit, so git checks the content against its hash.
pinned_for=6.1.1
if [ "$version" != "$pinned_for" ]; then
	echo "compile-adminer: the submodule commits below are for $pinned_for, not $version. Update them from git ls-tree v$version." >&2
	exit 1
fi

src=app/vendor/vrana/adminer
build=.cache/adminer-build

rm -rf "$build"
mkdir -p .cache
cp -R "$src" "$build"

submodule() {
	rm -rf "${build:?}/$1"
	git init -q "$build/$1"
	git -C "$build/$1" fetch -q --depth 1 "$2" "$3"
	git -C "$build/$1" checkout -q FETCH_HEAD
}
submodule adminer/static/jush https://github.com/vrana/jush 2de48f298628de73aea71f1af12a63f2ad03878f
submodule conf/JsShrink https://github.com/vrana/JsShrink b878053f1c2195cc189f86bf48f09b4a8d64d17e
submodule conf/PhpShrink https://github.com/vrana/PhpShrink c884864c0fa50a9502c42ed2a7f8fbf29922169e

# compile.php writes into the directory it runs in. drivers/ first: the release is built after
# it, and the plugin hashes compiled into adminer.php come from there.
root=$PWD
(
	cd "$build"
	mkdir drivers
	"$root/$frankenphp" php-cli compile.php drivers
	"$root/$frankenphp" php-cli compile.php
	"$root/$frankenphp" php-cli compile.php editor
)
cp "$build/adminer-$version.php" app/adminer.php
cp "$build/editor-$version.php" app/editor.php
