#!/bin/sh
# Start the e2e's mysql (tests/e2e/harness/fixture.php) if it is not up, and reseed it, so
# `make debug` has a database to log into. The seed drops and recreates, so a rerun only
# refreshes the data.
#
#     cli/demo-mysql.sh <container>
set -eu

name=$1
# In docker's own words when it cannot be used. "Is the daemon running?" and "permission denied"
# are different problems with different fixes, and both used to come out of `make debug` as
# "docker is not running" -- which sends you to look at a daemon that is already up. The daemon
# being reachable is a property of this machine and this user, so say which of the two it is.
if ! why=$(docker info 2>&1 >/dev/null); then
	echo "no mysql, the app opens without it:" >&2
	printf '%s\n' "$why" | grep -v '^$' | tail -2 >&2
	case $why in
		*"permission denied"*)
			echo "The daemon is up; this user cannot reach its socket. Join the docker group" >&2
			echo "(sudo usermod -aG docker \"$USER\") and log in again, or run docker rootless." >&2
			;;
	esac
	exit 1
fi
docker start "$name" >/dev/null 2>&1 || docker run -d --name "$name" \
	-e MYSQL_ROOT_PASSWORD=demo -e MYSQL_DATABASE=demo -p 127.0.0.1:53306:3306 mysql:8 >/dev/null
# Over TCP: the entrypoint's first server listens on the socket only, and is then restarted.
echo "waiting for mysql ..."
# A deadline, so a mysql that never comes up lets `make debug` open the app without it.
tries=60
until docker exec "$name" mysql -h 127.0.0.1 -uroot -pdemo demo -e 'SELECT 1' >/dev/null 2>&1; do
	tries=$((tries - 1))
	[ "$tries" -gt 0 ] || { echo "mysql did not come up in 60s" >&2; exit 1; }
	sleep 1
done
docker exec -i "$name" mysql -uroot -pdemo demo 2>/dev/null < tests/e2e/seed/mysql.sql
echo "mysql ready on 127.0.0.1:53306 (root / demo, database demo)"
