<?php
declare(strict_types=1);

/** Runs the browser end-to-end checks.
 *
 * Each business case is its own file — theme.test.php, settings.test.php — with its own browser and
 * its own app server, so one wedged check cannot leave state for the next. They run in
 * turn here; postgres is booted by the first (see fixture.php) and reused by the rest.
 * `make e2e` runs this.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Symfony\Component\Process\Process;

$root = dirname(__DIR__, 2);
$checks = ['theme.test.php', 'settings.test.php', 'sidebar-resize.test.php'];
$failed = [];

foreach ($checks as $check) {
	echo "── $check ──\n";
	$p = new Process(['./bin/frankenphp', 'php-cli', "tests/e2e/$check"], $root);
	$p->setTimeout(300);
	$p->run(function ($type, $buffer) {
		echo $buffer;
	});
	if (!$p->isSuccessful()) {
		$failed[] = $check;
	}
}

if ($failed) {
	echo "\ne2e FAILED: " . implode(', ', $failed) . "\n";
	exit(1);
}
echo "\ne2e ok — screenshots in tests/e2e/screenshots/\n";
