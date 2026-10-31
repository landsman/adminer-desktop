#!/bin/sh
# What cli/dresscode.sh would get wrong silently: passing when a type rule went quiet in app/ or cli/,
# or when phpstan.neon was not read, and leaving its bad files behind. Stubs dresscode. Run by `make qa`.
set -eu

script=$(cd "$(dirname "$0")/../.." && pwd)/cli/dresscode.sh
dir=$(mktemp -d)
trap 'rm -rf "$dir"' EXIT
cd "$dir"
mkdir -p app cli tests/dresscode
touch app/phpstan.neon cli/a.php tests/dresscode/override-missing.php
cat > stub <<'STUB'
#!/bin/sh
[ -e app/override-missing.php ] || exit "${CLEAN_EXIT:-0}"
printf '%b\n' "$REPORT"
exit 1
STUB
chmod +x stub
rule=upgrading.classes.Override
failed=0

check() { # <behaviour> <wanted exit> <report> [clean run's exit]
	got=0
	REPORT=$3 CLEAN_EXIT=${4:-0} "$script" ./stub >/dev/null 2>&1 || got=$?
	if [ "$got" = "$2" ] && [ ! -e app/override-missing.php ] && [ ! -e cli/override-missing.php ]; then
		echo "ok   $1"
	else
		echo "FAIL $1 (exit $got)"
		failed=1
	fi
}

check "passes when both bad files are reported" 0 "$rule\n$rule"
check "fails when only one is reported" 1 "$rule"
check "fails when neither is" 1 "OK"
check "fails when phpstan.neon was not read" 1 "$rule\n$rule\nthe types go without it"
check "fails on a finding in the real code" 1 "" 3

rm app/phpstan.neon
check "fails when app/phpstan.neon is gone" 1 "$rule\n$rule"
touch app/phpstan.neon

cat > stub <<'STUB'
#!/bin/sh
[ -e app/override-missing.php ] || exit 0
touch started
sleep 1
STUB
"$script" ./stub >/dev/null 2>&1 &
pid=$!
while [ ! -e started ]; do sleep 0.1; done
kill -TERM "$pid"
wait "$pid" || true
if [ -e app/override-missing.php ] || [ -e cli/override-missing.php ]; then
	echo "FAIL an interrupted run removes its bad files"
	failed=1
else
	echo "ok   an interrupted run removes its bad files"
fi
exit "$failed"
