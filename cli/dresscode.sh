#!/bin/sh
# Runs dresscode over app/ and each PHP file in cli/, then again with a file that breaks a type rule
# in both. A clean run cannot tell a checked file from one dropped without a word, and the type rules
# go silent with exit 0 when dresscode.neon leaves app/.
# Usage: cli/dresscode.sh <frankenphp>
set -eu

frankenphp=$1

run() {
	find cli -name '*.php' -exec "$frankenphp" php-cli app/vendor/bin/dresscode check --jobs 1 -c app/dresscode.neon --no-color "$@" app {} +
}

if [ ! -f app/phpstan.neon ]; then
	echo "app/phpstan.neon is missing: dresscode's type rules would run without phpstan.neon, and not say so" >&2
	exit 1
fi

run

trap 'rm -f app/override-missing.php cli/override-missing.php' EXIT
# dash skips the EXIT trap on a signal unless the signal itself exits
trap 'exit 130' INT TERM
for file in app/override-missing.php cli/override-missing.php; do
	cp tests/dresscode/override-missing.php "$file"
done

# A fixed format: under GitHub Actions the default one prints each finding twice.
output=$(run --format agent || true)
case $output in
*"the types go without it"*)
	printf '%s\n' "$output"
	echo "dresscode's type rules run without phpstan.neon" >&2
	exit 1
	;;
esac
found=$(printf '%s\n' "$output" | grep -c upgrading.classes.Override || true)
if [ "$found" != 2 ]; then
	printf '%s\n' "$output"
	echo "dresscode's type rules went silent: reported tests/dresscode/override-missing.php $found times of 2, once from app/ and once from cli/" >&2
	exit 1
fi
