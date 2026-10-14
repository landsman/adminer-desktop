#!/bin/sh
# Stop the frankenphp servers whose window is gone: `make kill-orphans`, and the SessionEnd hook in
# .claude/settings.json, so an agent's session leaves none behind.
#
# The launcher stops its server when its run loop returns, and on macOS a Cmd+Q never returns
# (AppKit exits the process), nor does a kill or a crash: the server stays, reparented to init
# and holding its port. That is the installed app as much as a checkout's or a worktree's. A server whose window is still
# open has its launcher as parent, and an e2e run's has PHP, so ppid 1 is what tells an orphan
# apart and nothing alive is touched.
#
# SIGTERM first, SIGKILL for what is still there two seconds on, and what it reports is what is
# gone, counted after.
#
#     cli/kill-orphans.sh            stop them
#     cli/kill-orphans.sh --filter   the orphans' pids among `ps -axo pid=,ppid=,command=` lines
#                                    on stdin, for tests/cli/kill-orphans-test.sh
set -eu

filter() {
	# The whole line, not a field: the installed app's path has a space in it.
	awk '$2 == 1 && index($0, "frankenphp php-server ") { print $1 }'
}

if [ "${1:-}" = "--filter" ]; then
	filter
	exit 0
fi

orphans() {
	ps -axo pid=,ppid=,command= | filter
}

found=$(orphans)
if [ -z "$found" ]; then
	echo "no orphaned frankenphp server"
	exit 0
fi
ps -o pid=,command= -p "$(echo "$found" | paste -sd, -)"

# shellcheck disable=SC2086 # one pid per word
kill $found 2>/dev/null || true
sleep 2
left=$(orphans)
if [ -n "$left" ]; then
	# shellcheck disable=SC2086
	kill -9 $left 2>/dev/null || true
	sleep 1
	left=$(orphans)
fi

total=$(echo "$found" | wc -l | tr -d ' ')
if [ -n "$left" ]; then
	echo "stopped $((total - $(echo "$left" | wc -l))) of $total; still running:" "$left" >&2
	exit 1
fi
echo "stopped $total"
