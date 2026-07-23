#!/bin/sh
# Bring up a themed Adminer to eyeball the adminer-desktop theme in a real browser.
#
# It solves the three things that otherwise trip you up:
#   1. Adminer refuses passwordless login, so SQLite through the form is a dead end here
#      (the app opens .sqlite via argv instead). This uses a throwaway postgres with a
#      password, which Adminer accepts.
#   2. Permanent login — and therefore any login — needs ADMINER_DESKTOP_DATA set, which
#      the launcher normally passes and `make serve` does not.
#   3. Demo data to actually look at, seeded from dev/preview.sql.
#
# Re-runnable: reuses the container if it is already up, and reapplies preview.sql every
# time — so edit the seed, re-run, and the new tables are there without recreating the DB.
# Leaves the server in the foreground; Ctrl-C stops it. Remove the DB with:
#   docker rm -f adminer-demo-pg
#
# Pair with dev/preview.login.js to drive the Playwright MCP straight to a table.
set -eu

PORT=18000
PG_PORT=55432
DATA="${TMPDIR:-/tmp}/adminer-desktop-preview"
ROOT=$(cd -- "$(dirname -- "$0")/.." && pwd)
mkdir -p "$DATA"

if ! docker ps --format '{{.Names}}' | grep -q '^adminer-demo-pg$'; then
	docker rm -f adminer-demo-pg >/dev/null 2>&1 || true
	docker run -d --name adminer-demo-pg \
		-e POSTGRES_PASSWORD=demo -e POSTGRES_DB=demo \
		-p "$PG_PORT:5432" postgres:18-alpine >/dev/null
fi

printf 'waiting for postgres'
until docker exec adminer-demo-pg pg_isready -U postgres >/dev/null 2>&1; do printf .; sleep 1; done
echo

# Idempotent seed, reapplied each run. ON_ERROR_STOP so a broken preview.sql fails loudly
# instead of leaving a half-seeded database that looks fine until a table is missing.
docker exec -i adminer-demo-pg psql -U postgres -d demo -v ON_ERROR_STOP=1 \
	< "$ROOT/dev/preview.sql" >/dev/null

echo
echo "  Adminer:  http://127.0.0.1:$PORT/adminer.php"
echo "  Login  →  System: PostgreSQL   Server: 127.0.0.1:$PG_PORT"
echo "            User: postgres   Password: demo   Database: demo"
echo

ADMINER_DESKTOP_DATA="$DATA" "$ROOT/bin/frankenphp" php-server \
	--root "$ROOT/app" --listen "127.0.0.1:$PORT" --no-compress
