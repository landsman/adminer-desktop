#!/bin/sh
# Put a Developer ID certificate somewhere `make bundle` can sign with it.
#
# CI needs this because a runner has no keychain and no GUI to unlock one. You do not: a
# certificate double-clicked into your login keychain stays there, and codesign finds it
# without any of this. `make notarize MACOS_SIGN_ID=...` is the whole local story.
#
# It is here for the other reason. macOS runners bill at 10x and the three-platform build is
# manual dispatch precisely so it is not iterated against, so the CI signing path wants to be
# rehearsable on this machine first -- and shell that lives in a YAML `run:` block is neither
# linted nor runnable anywhere. Same script both places, so a green rehearsal means something.
#
# Which is only true if it is safe to run here. Every CI recipe for this reaches for
# `security default-keychain -s`, and on an ephemeral runner that costs nothing; on your own
# Mac it points the default away from login and leaves it there if this exits early. codesign
# searches the whole keychain list, so appending to that list was always enough.
set -eu

KEYCHAIN="${TMPDIR:-/tmp}/adminer-desktop-signing.keychain-db"

# The search list minus our own entry, so repeated imports replace rather than accumulate,
# and so removal has something to put back.
others() {
	security list-keychains -d user | tr -d ' "' | grep -vF "$KEYCHAIN" || true
}

if [ "${1:-import}" = remove ]; then
	# delete-keychain drops it from the search list too, so this needs no second step.
	security delete-keychain "$KEYCHAIN" 2>/dev/null || true
	echo "keychain removed"
	exit 0
fi

: "${MACOS_CERT_P12:?path to the Developer ID .p12, or its base64}"
: "${MACOS_CERT_PASSWORD:?the password that .p12 was exported with}"

# Anything that fails below leaves the keychain half-built and in the search list, which on a
# runner nobody sees and here you would have to know to clean up. `security import` refusing a
# .p12 is the likely one, so unwind unless the last line was reached.
DONE=
TMP_P12=
cleanup() {
	[ -z "$TMP_P12" ] || rm -f "$TMP_P12"
	[ -n "$DONE" ] || security delete-keychain "$KEYCHAIN" 2>/dev/null || true
}
trap cleanup EXIT INT TERM

# A path locally, base64 in CI, because a secret cannot hold a file. Both, so neither side
# has to shape its input for the other's convenience.
P12="$MACOS_CERT_P12"
if [ ! -f "$P12" ]; then
	TMP_P12="${TMPDIR:-/tmp}/adminer-desktop-cert.p12"
	printf '%s' "$MACOS_CERT_P12" | base64 -d > "$TMP_P12"
	P12="$TMP_P12"
fi

# Generated, not literal: it guards a keychain that holds one certificate for the length of
# one build, so its only real job is to not be a hardcoded password in a public repo.
PASS="$(uuidgen)"

security delete-keychain "$KEYCHAIN" 2>/dev/null || true
security create-keychain -p "$PASS" "$KEYCHAIN"
# Unlocked and told to stay that way: the default is a 5-minute idle lock, and `make bundle`
# spends longer than that on composer and rsync before it reaches codesign.
security set-keychain-settings -lut 21600 "$KEYCHAIN"
security unlock-keychain -p "$PASS" "$KEYCHAIN"

# shellcheck disable=SC2046  # word splitting is the point: one argument per keychain path.
security list-keychains -d user -s "$KEYCHAIN" $(others)

security import "$P12" -k "$KEYCHAIN" -P "$MACOS_CERT_PASSWORD" -T /usr/bin/codesign
# Without this codesign asks for the keychain password the first time it uses the key, which
# on a runner means the job hangs until it times out rather than failing.
security set-key-partition-list -S apple-tool:,apple:,codesign: -s -k "$PASS" "$KEYCHAIN" > /dev/null

# "1 identity imported" only means the file parsed. This is the line that says codesign can
# use it: an expired certificate, or a Mac Development one where Developer ID was wanted,
# imports perfectly happily and then shows up here as nothing. Failing now beats failing
# minutes later inside `make bundle`, where codesign has far less to say about why.
IDENTITIES="$(security find-identity -v -p codesigning "$KEYCHAIN")"
if ! printf '%s' "$IDENTITIES" | grep -q 'Developer ID Application'; then
	echo "keychain.sh: no Developer ID Application identity in that .p12." >&2
	echo "  expired, the wrong certificate type, or exported without its private key?" >&2
	printf '%s\n' "$IDENTITIES" >&2
	exit 1
fi

DONE=1
echo "imported into $KEYCHAIN -- \`make keychain-clean\` removes it"
printf '%s\n' "$IDENTITIES"
