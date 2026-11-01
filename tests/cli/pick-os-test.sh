#!/bin/sh
# What cli/pick-os.sh would get wrong silently: a choice mapped to the wrong OS, a value the PHP
# enum does not know, or waiting on input with no terminal to answer from.
set -u

script="$(cd "$(dirname "$0")/../.." && pwd)/cli/pick-os.sh"
rc=0

check() {
	if [ "$2" = "$3" ]; then
		echo "ok   $1"
	else
		echo "FAIL $1: wanted '$3', got '$2'"
		rc=1
	fi
}

check "Enter means this machine" "$("$script" answer "")" ""
check "1 means this machine" "$("$script" answer 1)" ""
check "2 means Windows" "$("$script" answer 2)" "windows"
check "3 means Linux" "$("$script" answer 3)" "linux"
check "4 means macOS" "$("$script" answer 4)" "mac"
check "no terminal asks nothing and answers this machine" "$("$script" </dev/null 2>&1)" ""

if "$script" answer 9 >/dev/null 2>&1; then
	echo "FAIL an unknown choice is refused"
	rc=1
else
	echo "ok   an unknown choice is refused"
fi

exit "$rc"
