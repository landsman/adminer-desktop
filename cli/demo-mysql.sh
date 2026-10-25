#!/bin/sh
# Start the e2e's mysql (tests/e2e/harness/fixture.php) if it is not up, and reseed it, so
# `make debug` has a database to log into. The seed drops and recreates, so a rerun only
# refreshes the data.
#
#     cli/demo-mysql.sh <container>
set -eu

name=$1
docker info >/dev/null 2>&1 || exit 1
docker start "$name" >/dev/null 2>&1 || docker run -d --name "$name" \
	-e MYSQL_ROOT_PASSWORD=demo -e MYSQL_DATABASE=demo -p 53306:3306 mysql:8 >/dev/null
# Over TCP: the entrypoint's first server listens on the socket only, and is then restarted.
echo "waiting for mysql ..."
until docker exec "$name" mysql -h 127.0.0.1 -uroot -pdemo demo -e 'SELECT 1' >/dev/null 2>&1; do sleep 1; done
docker exec -i "$name" mysql -uroot -pdemo demo 2>/dev/null < tests/e2e/seed/mysql.sql
echo "mysql ready on 127.0.0.1:53306 (root / demo, database demo)"
