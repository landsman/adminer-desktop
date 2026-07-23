<?php
declare(strict_types=1);

/** Browser end-to-end check for the Adminer Desktop theme.
 *
 * Boots a throwaway postgres and the app, logs in, and asserts the theme is applied in
 * both light and dark — leaving screenshots behind in tests/e2e/screenshots/. Run it with
 * `mise run e2e` (or ./bin/frankenphp php-cli tests/e2e/run.php).
 *
 * Self-contained on purpose: Adminer refuses passwordless login and needs the data
 * directory the launcher normally passes, so a plain `make serve` cannot be logged into.
 * This owns the whole fixture — postgres, the seed, the server, the browser — and tears
 * the server down again, so it is the same to run locally and in CI.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Playwright\Playwright;
use Symfony\Component\Process\Process;

$root = dirname(__DIR__, 2);
$pgPort = 55432;
$appPort = 18080; // not 18000, so `make serve` can stay up alongside this
$data = sys_get_temp_dir() . '/adminer-desktop-e2e';
$shots = __DIR__ . '/screenshots';
@mkdir($data, 0700, true);
@mkdir($shots, 0777, true);

// --- fixture: a throwaway postgres, seeded ---------------------------------

$running = trim((string) shell_exec("docker ps --format '{{.Names}}' | grep -x adminer-demo-pg 2>/dev/null"));
if ($running === '') {
	(new Process([
		'docker', 'run', '-d', '--name', 'adminer-demo-pg',
		'-e', 'POSTGRES_PASSWORD=demo', '-e', 'POSTGRES_DB=demo',
		'-p', "$pgPort:5432", 'postgres:18-alpine',
	]))->mustRun();
}

$deadline = time() + 30;
while (true) {
	$ready = new Process(['docker', 'exec', 'adminer-demo-pg', 'pg_isready', '-U', 'postgres']);
	$ready->run();
	if ($ready->isSuccessful()) {
		break;
	}
	if (time() > $deadline) {
		throw new RuntimeException('postgres did not become ready');
	}
	sleep(1);
}

$seed = new Process(['docker', 'exec', '-i', 'adminer-demo-pg', 'psql', '-U', 'postgres', '-d', 'demo', '-v', 'ON_ERROR_STOP=1']);
$seed->setInput((string) file_get_contents(__DIR__ . '/seed.sql'));
$seed->mustRun();

// --- fixture: the app ------------------------------------------------------

$server = new Process(
	[$root . '/bin/frankenphp', 'php-server', '--root', $root . '/app', '--listen', "127.0.0.1:$appPort", '--no-compress'],
	null,
	['ADMINER_DESKTOP_DATA' => $data],
);
$server->start();

$base = "http://127.0.0.1:$appPort/adminer.php";
$deadline = time() + 15;
while (true) {
	$ctx = stream_context_create(['http' => ['timeout' => 1, 'ignore_errors' => true]]);
	if (@file_get_contents($base, false, $ctx) !== false) {
		break;
	}
	if (time() > $deadline) {
		$server->stop();
		throw new RuntimeException('the app did not start');
	}
	usleep(200_000);
}

// --- the check: log in and confirm the theme is applied, light and dark ----

$select = "http://127.0.0.1:$appPort/adminer.php?" . http_build_query([
	'pgsql' => "127.0.0.1:$pgPort",
	'username' => 'postgres',
	'db' => 'demo',
	'ns' => 'public',
	'select' => 'users',
]);

$failures = [];
try {
	foreach (['light', 'dark'] as $scheme) {
		// colorScheme is a context option, so it goes under 'context' — passed at the top
		// level (next to the launch option 'headless') it is silently ignored.
		$options = ['headless' => true];
		if ($scheme === 'dark') {
			$options['context'] = ['colorScheme' => 'dark'];
		}
		$context = Playwright::chromium($options);
		$page = $context->newPage();

		$page->goto($base);
		$page->locator('select[name="auth[driver]"]')->selectOption('pgsql');
		usleep(400_000); // Adminer rebuilds the driver's fields on change; let that settle
		$page->locator('input[name="auth[server]"]')->fill("127.0.0.1:$pgPort");
		$page->locator('input[name="auth[username]"]')->fill('postgres');
		$page->locator('input[name="auth[password]"]')->fill('demo');
		$page->locator('input[name="auth[db]"]')->fill('demo');
		// Submit the login form directly rather than clicking: headless Adminer rebuilds
		// the driver's fields on change, which leaves the submit button intermittently
		// "not actionable", and this is independent of the button's markup and label.
		$page->evaluate("() => document.querySelector('[name=\"auth[driver]\"]').form.requestSubmit()");
		$page->waitForLoadState('networkidle');

		$page->goto($select);
		$page->waitForLoadState('networkidle');
		$page->screenshot("$shots/users-$scheme.png");

		$title = $page->title();
		if (!str_contains($title, 'users')) {
			$failures[] = "$scheme: not logged in (title: $title)";
		}
		// The theme's own token is only defined by our stylesheet, so a non-empty value
		// proves the Adminer Desktop CSS actually loaded and applied — not just that a page
		// rendered.
		$accent = $page->evaluate("() => getComputedStyle(document.documentElement).getPropertyValue('--ad-accent').trim()");
		if (!is_string($accent) || $accent === '') {
			$failures[] = "$scheme: theme not applied (--ad-accent is empty)";
		}
		// And that the scheme itself was emulated — otherwise a dark run silently renders
		// light and the screenshot is the only tell.
		$isDark = (bool) $page->evaluate("() => matchMedia('(prefers-color-scheme: dark)').matches");
		if ($isDark !== ($scheme === 'dark')) {
			$failures[] = "$scheme: prefers-color-scheme was not emulated";
		}

		$context->close();
	}
} finally {
	$server->stop();
}

if ($failures) {
	fwrite(STDERR, implode("\n", $failures) . "\n");
	echo count($failures) . " e2e failure(s)\n";
	exit(1);
}

echo "e2e ok — screenshots in tests/e2e/screenshots/\n";
