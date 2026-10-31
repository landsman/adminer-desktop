#!/bin/sh
# Build frankenphp with only the extensions the app uses: the upstream binary is nearly all of
# every package, and most of it goes unused. See `make frankenphp-build`.
#
#   build-frankenphp.sh asset                        the binary's name on this machine
#   build-frankenphp.sh image <version> <revision>   the registry reference for that binary
#   build-frankenphp.sh build <version> <extensions> <out dir>
#   build-frankenphp.sh smoke <binary> <extensions>  fail on a missing extension
#   build-frankenphp.sh size <binary>                unpacked and gzipped, in bytes
#   build-frankenphp.sh publish <binary> <reference> push it, and hand CI the digest
#
# GITHUB_TOKEN keeps the build under GitHub's API limit: it makes about 40 requests, and the
# anonymous allowance is 60 an hour.
set -eu

REGISTRY=ghcr.io/landsman/adminer-desktop/frankenphp

# nomercure: frankenphp's own Caddy module imports Mercure unless told not to, whatever
# XCADDY_ARGS says. XCADDY_ARGS must not be empty, or the defaults come back.
GO_TAGS=nomercure
CADDY_MODULES="--with github.com/dunglas/caddy-cbrotli"

asset() {
	case "$(uname -s)-$(uname -m)" in
		Darwin-arm64) echo frankenphp-mac-arm64 ;;
		Linux-x86_64) echo frankenphp-linux-x86_64 ;;
		Linux-aarch64 | Linux-arm64) echo frankenphp-linux-aarch64 ;;
		*)
			echo "no frankenphp build for $(uname -s)-$(uname -m)" >&2
			exit 1
			;;
	esac
}

build() {
	version=$1 extensions=$2 out=$3
	mkdir -p "$out"
	out=$(cd "$out" && pwd)
	if [ "$(uname -s)" = Darwin ]; then
		src=$(mktemp -d)
		git clone -q --depth 1 --branch "v$version" https://github.com/php/frankenphp "$src"
		(cd "$src" && OUTPUT_DIR="$out" SPC_LOGS_DIR="$out/log" FRANKENPHP_VERSION="$version" \
			PHP_EXTENSIONS="$extensions" XCADDY_ARGS="$CADDY_MODULES" \
			SPC_CMD_VAR_FRANKENPHP_GO_TAGS="$GO_TAGS" ./build-static.sh)
	else
		# Docker because a build pulls in a toolchain nobody wants on the machine. Only Linux
		# can: a container cannot produce a Mach-O binary.
		docker run --rm -v "$out:/output" -e OUTPUT_DIR=/output -e SPC_LOGS_DIR=/output/log \
			-e GITHUB_TOKEN -e PHP_EXTENSIONS="$extensions" -e XCADDY_ARGS="$CADDY_MODULES" \
			-e SPC_CMD_VAR_FRANKENPHP_GO_TAGS="$GO_TAGS" \
			"dunglas/frankenphp:static-builder-musl-$version"
	fi
}

smoke() {
	binary=$1 extensions=$2
	"$binary" version
	loaded=$("$binary" php-cli -r 'echo strtolower(implode("\n", get_loaded_extensions()));')
	missing=
	for extension in $(echo "$extensions" | tr ',' ' '); do
		name=$extension
		[ "$name" = opcache ] && name="zend opcache"
		echo "$loaded" | grep -Fxq "$name" || missing="$missing $extension"
	done
	if [ -n "$missing" ]; then
		echo "missing from $binary:$missing" >&2
		exit 1
	fi
	echo "all extensions loaded"
}

size() {
	echo "$(basename "$1"): $(wc -c <"$1" | tr -d ' ') unpacked, $(gzip -6 -c "$1" | wc -c | tr -d ' ') with gzip -6"
}

publish() {
	dir=$(dirname "$1") file=$(basename "$1") reference=$2
	echo "$GITHUB_TOKEN" | oras login ghcr.io -u "$GITHUB_ACTOR" --password-stdin
	digest=$(cd "$dir" && oras push "$reference" "$file" --format go-template='{{.digest}}')
	echo "$reference $digest"
	echo "digest=$digest" >>"${GITHUB_OUTPUT:-/dev/null}"
}

command=${1:-}
[ $# -gt 0 ] && shift
case "$command" in
	asset) asset ;;
	image) echo "$REGISTRY:$1-$2-$(asset | sed 's/^frankenphp-//')" ;;
	build) build "$@" ;;
	smoke) smoke "$@" ;;
	size) size "$@" ;;
	publish) publish "$@" ;;
	*)
		sed -n '5,10p' "$0" >&2
		exit 2
		;;
esac
