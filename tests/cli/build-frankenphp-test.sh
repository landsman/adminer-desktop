#!/bin/sh
# What cli/build-frankenphp.sh would get wrong silently: a binary published with an extension
# missing, or under another platform's name. No network and no real build.
set -u
script="$(cd "$(dirname "$0")/../.." && pwd)/cli/build-frankenphp.sh"
t=$(mktemp -d)
trap 'rm -rf "$t"' EXIT
rc=0

check() {
	if [ "$2" = "$3" ]; then
		echo "ok   $1"
	else
		echo "FAIL $1: wanted '$2', got '$3'"
		rc=1
	fi
}

cat >"$t/binary" <<'EOF'
#!/bin/sh
[ "$1" = version ] && { echo "FrankenPHP stub"; exit 0; }
printf 'core\nzend opcache\nmysqli'
EOF
chmod +x "$t/binary"

sh "$script" smoke "$t/binary" opcache,mysqli >/dev/null 2>&1
check "smoke passes when every extension is loaded, opcache by its real name" 0 $?

sh "$script" smoke "$t/binary" mysqli,pgsql >/dev/null 2>&1
check "smoke fails when an extension is missing" 1 $?

# A substring must not count: "mysql" is not loaded just because "mysqli" is.
sh "$script" smoke "$t/binary" mysql >/dev/null 2>&1
check "smoke does not take mysqli for mysql" 1 $?

mkdir "$t/bin"
stub_uname() {
	cat >"$t/bin/uname" <<STUB
#!/bin/sh
[ "\$1" = -s ] && echo $1 || echo $2
STUB
	chmod +x "$t/bin/uname"
}

check_asset() {
	stub_uname "$1" "$2"
	check "$1 $2 builds $3" "$3" "$(PATH="$t/bin:$PATH" sh "$script" asset)"
}

check_asset Darwin arm64 frankenphp-mac-arm64
check_asset Linux x86_64 frankenphp-linux-x86_64
check_asset Linux aarch64 frankenphp-linux-aarch64

check "image names the version, revision and platform" \
	ghcr.io/landsman/adminer-desktop/frankenphp:1.13.3-2-linux-aarch64 \
	"$(PATH="$t/bin:$PATH" sh "$script" image 1.13.3 2)"

stub_uname Plan9 mips
PATH="$t/bin:$PATH" sh "$script" asset >/dev/null 2>&1
check "an unknown platform fails rather than naming a binary" 1 $?

cat >"$t/bin/oras" <<STUB
#!/bin/sh
echo "\$@" >>"$t/oras.log"
[ "\$1" = push ] && echo sha256:abc
exit 0
STUB
chmod +x "$t/bin/oras"
GITHUB_TOKEN=x GITHUB_ACTOR=me GITHUB_OUTPUT="$t/output" PATH="$t/bin:$PATH" sh "$script" publish "$t/binary" registry/image:tag >/dev/null
check "publish hands CI the digest it pushed" "digest=sha256:abc" "$(cat "$t/output")"
check "publish pushes the file to the reference it was given" 1 "$(grep -c 'push registry/image:tag binary' "$t/oras.log")"

exit $rc
