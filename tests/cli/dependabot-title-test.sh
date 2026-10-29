#!/usr/bin/env bash
# The title is the one thing here that fails silently: a wrong one is still a title. Run by
# `make qa`.
set -euo pipefail
t="$(cd "$(dirname "$0")/../../cli" && pwd)/dependabot-title.sh"
fail=0
check() {
  local got
  got=$("$t" "${@:2}")
  [[ $got == "$1" ]] || { echo "want: $1"; echo " got: $got"; fail=1; }
}

check "deps: bump latte/latte from 3.1.6 to 3.1.7" \
  "deps: bump latte/latte from 3.1.6 to 3.1.7 in /app" "latte/latte" "3.1.6" "3.1.7"
check "deps: bump latte/latte and tracy/tracy" \
  "deps: bump the composer group in /app with 2 updates" "latte/latte, tracy/tracy"
check "deps: bump oxlint, oxfmt and playwright" \
  "deps: bump the npm group across 1 directory with 3 updates" "oxlint, oxfmt, playwright" "1" "2"
check "deps: bump a, b, c and 2 more" \
  "deps: bump the go group with 5 updates" "a,b,c,d,e"
check "deps: bump actions/checkout" "Bump actions/checkout" "actions/checkout"

[[ $fail == 0 ]] && echo "dependabot-title ok"
exit $fail
