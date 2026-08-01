<?php
declare(strict_types=1);

/** Shared fixture for the browser end-to-end checks.
 *
 * Each check is its own file (theme.test.php, settings.test.php) and its own browser process; they
 * all require this for the parts they have in common — a seeded throwaway postgres and the
 * app served with a data dir, so Adminer's passwordless block is satisfied and `make serve`
 * cannot otherwise be logged into. e2e_boot() returns the running server and the urls;
 * e2e_login() logs a page in; e2e_done() tears the server down and reports.
 *
 * Postgres is left running and reused between the files (and between runs); only the app
 * server is per-file, and it is cheap to start.
 */

require dirname(__DIR__, 2) . '/app/vendor/autoload.php';

use Symfony\Component\Process\Process;

/** Boot postgres (once) and a fresh app server; return everything a check needs.
 * @return array{root:string, pgPort:int, shots:string, server:Process, base:string, select:string, data:string}
 */
function e2e_boot(int $appPort = 18080): array
{
	$root = dirname(__DIR__, 2);
	$pgPort = 55432;
	$data = sys_get_temp_dir() . '/adminer-desktop-e2e';
	$shots = __DIR__ . '/screenshots';
	@mkdir($data, 0700, true);
	@mkdir($shots, 0777, true);

	// A throwaway postgres, seeded — reuse one already running.
	$running = trim((string) shell_exec("docker ps --format '{{.Names}}' | grep -x adminer-demo-pg 2>/dev/null"));
	if ($running === '') {
		(new Process([
			'docker', 'run', '-d', '--name', 'adminer-demo-pg',
			'-e', 'POSTGRES_PASSWORD=demo', '-e', 'POSTGRES_DB=demo',
			'-p', "$pgPort:5432", 'postgres:18-alpine',
		]))->mustRun();

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
	}

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

	$select = "http://127.0.0.1:$appPort/adminer.php?" . http_build_query([
		'pgsql' => "127.0.0.1:$pgPort",
		'username' => 'postgres',
		'db' => 'demo',
		'ns' => 'public',
		'select' => 'users',
	]);

	// $data too: the preferences a check saves land in $data/settings.json, which is where
	// asserting that Save persisted anything has to look.
	return compact('root', 'pgPort', 'shots', 'server', 'base', 'select', 'data');
}

/** Log a page into the demo database.
 *
 * Verified and retried, because the 400ms below is a guess: adminer rebuilds the driver's
 * fields on change, and a fill that lands mid-rebuild posts an empty field and comes back as
 * the login page. Whatever the check does next then fails on an empty page, which reads as
 * anything but a login problem — the failure it produced was "the edit form has no fields".
 */
function e2e_login($page, string $base, int $pgPort): void
{
	for ($attempt = 1; $attempt <= 3; $attempt++) {
		$page->goto($base);
		$page->locator('select[name="auth[driver]"]')->selectOption('pgsql');
		usleep(400_000 * $attempt); // let the rebuild settle, and wait longer each time round
		$page->locator('input[name="auth[server]"]')->fill("127.0.0.1:$pgPort");
		$page->locator('input[name="auth[username]"]')->fill('postgres');
		$page->locator('input[name="auth[password]"]')->fill('demo');
		$page->locator('input[name="auth[db]"]')->fill('demo');
		// Submit the login form directly rather than clicking: headless Adminer rebuilds the
		// driver's fields on change, which leaves the submit button intermittently "not
		// actionable", and this is independent of the button's markup and label.
		$page->evaluate("() => document.querySelector('[name=\"auth[driver]\"]').form.requestSubmit()");
		$page->waitForLoadState('networkidle');
		// A rejected login comes back as the login page, and its title says so. The title is a
		// driver call, unlike an evaluate, which throws when it lands while adminer's answer to
		// a good login is still committing.
		if (!str_starts_with($page->title(), 'Login')) {
			return;
		}
	}
	throw new RuntimeException('could not log in after 3 attempts');
}

/** Stop the server and report one check file's result, exiting with its status. */
function e2e_done(Process $server, array $failures, string $name): never
{
	$server->stop();
	if ($failures) {
		fwrite(STDERR, implode("\n", $failures) . "\n");
		echo "$name: " . count($failures) . " failure(s)\n";
		exit(1);
	}
	echo "$name ok — screenshots in tests/e2e/screenshots/\n";
	exit(0);
}
