#!/usr/bin/env bash
# Prints the title a Dependabot PR should have: its own `deps(<service>)` prefix, then what
# was actually bumped. A group PR otherwise says "bump the all group with 3 updates", which
# names nothing.
#
#   dependabot-title.sh <current title> <names, comma-separated> [from] [to]
#
# from/to are only used for a single dependency; a group lists names.
set -euo pipefail

title=$1 names=$2 from=${3:-} to=${4:-}
prefix=deps
[[ $title == *": "* ]] && prefix=${title%%: *}

IFS=',' read -ra deps <<<"${names// /}"
n=${#deps[@]}

if ((n == 1)); then
  what=${deps[0]}
  [[ -n $from && -n $to ]] && what+=" from $from to $to"
elif ((n <= 3)); then
  what=$(printf '%s, ' "${deps[@]:0:n-1}")
  what="${what%, } and ${deps[n-1]}"
else
  what="${deps[0]}, ${deps[1]}, ${deps[2]} and $((n - 3)) more"
fi

echo "$prefix: bump $what"
