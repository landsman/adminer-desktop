#!/bin/sh
# Ask which OS `make debug` should look like, and print its Os or LinuxDistro case for
# ADMINER_DESKTOP_SIMULATE_OS: nothing for this machine. The question goes to stderr, because the
# Makefile captures stdout. Without a terminal it asks nothing and answers this machine.
#
#     cli/pick-os.sh           ask, print the answer
#     cli/pick-os.sh answer N  print the case for menu choice N
set -eu

answer() {
	case "$1" in
		"" | 1) ;;
		2) echo windows ;;
		3) echo linux ;;
		4) echo mac ;;
		5) echo ubuntu ;;
		6) echo kubuntu ;;
		7) echo mint ;;
		8) echo omarchy ;;
		*)
			echo "no such choice: $1" >&2
			return 1
			;;
	esac
}

if [ "${1:-}" = answer ]; then
	answer "${2:-}"
	exit
fi

[ -t 0 ] || exit 0

echo "Debug as which OS?  1) this machine  2) Windows  3) Linux  4) macOS" >&2
echo "                    5) Ubuntu  6) Kubuntu  7) Linux Mint  8) Omarchy" >&2
until
	printf '[1] ' >&2
	read -r choice || exit 0
	answer "$choice"
do :; done
