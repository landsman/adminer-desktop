<?php
declare(strict_types=1);

/** Shared fixture for the browser end-to-end checks.
 *
 * What a scenario in tests/e2e/features/ needs before its first step can run: a seeded throwaway
 * database in docker, and the app served with a data dir of its own so Adminer's passwordless
 * block is satisfied. The contexts in tests/e2e/bootstrap/ call e2e_boot() once per driver, then
 * e2e_login() to log a page in, e2e_url() to build a link into the demo database, and
 * e2e_report() to save the page a step failed on.
 *
 * Which driver a process is testing arrives in ADMINER_DESKTOP_E2E_DRIVER, set by the context
 * from the Behat suite it belongs to — see tests/e2e/behat.yml.
 */

require dirname(__DIR__, 3) . '/app/vendor/autoload.php';

use Playwright\Browser\BrowserInterface;
use Playwright\Configuration\PlaywrightConfig;
use Playwright\Page\PageInterface;
use Playwright\PlaywrightFactory;
use Symfony\Component\Process\Process;

const E2E_DATABASE = 'demo';

/** What each driver is called, how to start it and how to log into it.
 *
 * `key` is what Adminer calls the driver in the URL and in the login form, which is not always
 * the name of the database — MySQL is "server" there for backwards compatibility. `container` is
 * the postgres one `make demo` and `make destroy` already know about, so the e2e and the demo
 * share a database rather than each leaving one behind.
 *
 * @return array{name:string, key:string, container:string, image:string, port:int, hostPort:int,
 *     username:string, password:string, env:string[], appPort:int}
 */
function e2e_driver(): array
{
	$drivers = [
		'pgsql' => [
			'key' => 'pgsql',
			'container' => 'adminer-demo-pg',
			'image' => 'postgres:18-alpine',
			'port' => 5432,
			'hostPort' => 55432,
			'username' => 'postgres',
			'password' => 'demo',
			'env' => ['POSTGRES_PASSWORD=demo', 'POSTGRES_DB=' . E2E_DATABASE],
			'appPort' => 18080,
		],
		'mysql' => [
			'key' => 'server',
			'container' => 'adminer-demo-mysql',
			'image' => 'mysql:8',
			'port' => 3306,
			'hostPort' => 53306,
			'username' => 'root',
			'password' => 'demo',
			'env' => ['MYSQL_ROOT_PASSWORD=demo', 'MYSQL_DATABASE=' . E2E_DATABASE],
			'appPort' => 18090,
		],
	];
	$name = getenv('ADMINER_DESKTOP_E2E_DRIVER') ?: 'pgsql';
	if (!isset($drivers[$name])) {
		throw new RuntimeException("unknown driver '$name', use " . implode(' or ', array_keys($drivers)));
	}
	return ['name' => $name] + $drivers[$name];
}

/** Start the database and seed it, or reuse one that is already running.
 *
 * @param array<string, mixed> $driver what e2e_driver() returned
 * @return string hostname:port to connect to
 */
function e2e_database(array $driver): string
{
	$name = (string) $driver['container'];
	$running = new Process(['docker', 'ps', '--filter', "name=^/$name$", '--format', '{{.Names}}']);
	$running->run();
	if (trim($running->getOutput()) !== $name) {
		$run = new Process(array_merge(
			['docker', 'run', '-d', '--name', $name],
			array_merge(...array_map(fn (string $env): array => ['-e', $env], (array) $driver['env'])),
			['-p', "{$driver['hostPort']}:{$driver['port']}", (string) $driver['image']],
		));
		$run->run();
		if (!$run->isSuccessful()) {
			// Started but stopped, rather than absent: `docker start` is what revives it, and a
			// second `docker run` on the same name only ever fails.
			(new Process(['docker', 'start', $name]))->mustRun();
		}
	}
	e2e_wait_for_database($driver);
	// Always, reused or not. The seed drops what it creates, so this is what a rerun starts from —
	// and editing seed/*.sql then reaches the database without `make destroy` first, which is the
	// papercut the old fixture documented rather than fixed.
	e2e_seed($driver);
	return '127.0.0.1:' . $driver['hostPort'];
}

/** Wait until the server inside the container answers, not merely until the container exists.
 * @param array<string, mixed> $driver
 */
function e2e_wait_for_database(array $driver): void
{
	$name = (string) $driver['container'];
	$probe = $driver['name'] === 'pgsql'
		? ['docker', 'exec', $name, 'pg_isready', '-U', (string) $driver['username']]
		: ['docker', 'exec', $name, 'mysqladmin', 'ping', '-u', (string) $driver['username'], '-p' . $driver['password']];
	$deadline = time() + 60;
	while (true) {
		$ready = new Process($probe);
		$ready->run();
		if ($ready->isSuccessful()) {
			return;
		}
		if (time() > $deadline) {
			throw new RuntimeException("{$driver['name']} did not become ready");
		}
		sleep(1);
	}
}

/** Apply seed/<driver>.sql through the client inside the container, so nothing has to be installed.
 * @param array<string, mixed> $driver
 */
function e2e_seed(array $driver): void
{
	$name = (string) $driver['container'];
	$client = $driver['name'] === 'pgsql'
		? ['docker', 'exec', '-i', $name, 'psql', '-U', (string) $driver['username'], '-d', E2E_DATABASE, '-v', 'ON_ERROR_STOP=1', '-q']
		: ['docker', 'exec', '-i', $name, 'mysql', '-u', (string) $driver['username'], '-p' . $driver['password'], E2E_DATABASE];
	$seed = new Process($client);
	$seed->setTimeout(120);
	$seed->setInput((string) file_get_contents(dirname(__DIR__) . "/seed/{$driver['name']}.sql"));
	$seed->mustRun();
}

/** Run one statement against the demo database without a browser, and return what it printed.
 *
 * The client inside the container again, for the same reason: a scenario that arranges a row or
 * checks one that should not exist needs no driver on this side.
 * @param array<string, mixed> $driver
 */
function e2e_sql(array $driver, string $sql): string
{
	$name = (string) $driver['container'];
	$run = new Process($driver['name'] === 'pgsql'
		? ['docker', 'exec', $name, 'psql', '-U', (string) $driver['username'], '-d', E2E_DATABASE, '-tAc', $sql]
		: ['docker', 'exec', $name, 'mysql', '-N', '-B', '-u', (string) $driver['username'], '-p' . $driver['password'], E2E_DATABASE, '-e', $sql]);
	$run->run();
	return trim($run->getOutput());
}

/** Serve the app and return everything a scenario needs.
 *
 * @return array{root:string, data:string, artifacts:string, server:Process, base:string,
 *     database:string, driver:array<string, mixed>}
 */
function e2e_boot(): array
{
	$root = dirname(__DIR__, 3);
	$artifacts = dirname(__DIR__) . '/artifacts';
	$driver = e2e_driver();
	// One data dir per driver: the suites run in one process and settings.json is what half the
	// scenarios assert on, so a shared one would have them writing over each other.
	$data = sys_get_temp_dir() . "/adminer-desktop-e2e-{$driver['name']}";
	@mkdir($data, 0700, true);
	@mkdir($artifacts, 0777, true);
	$database = e2e_database($driver);

	// The next free port rather than the one asked for: a killed run leaves its server behind, and
	// every scenario would then wait on a port it is never going to get.
	$port = e2e_free_port((int) $driver['appPort']);
	$server = new Process(
		[$root . '/bin/frankenphp', 'php-server', '--root', $root . '/app', '--listen', "127.0.0.1:$port", '--no-compress'],
		null,
		['ADMINER_DESKTOP_DATA' => $data],
	);
	// frankenphp logs every request it serves and nothing here ever reads that pipe, so it fills:
	// sixty-four kilobytes in, the server blocks writing to it and stops answering. From the
	// browser that looks like a page which commits and then never finishes loading, a scenario or
	// two into the run — and the log is of no use to a scenario anyway.
	$server->disableOutput();
	$server->start();

	$base = "http://127.0.0.1:$port/adminer.php";
	$deadline = time() + 30;
	$context = stream_context_create(['http' => ['timeout' => 1, 'ignore_errors' => true]]);
	// The login form, not merely an answer: anything else listening on the port would answer too,
	// and every scenario would then fail at logging in to a page that is not Adminer.
	while (!str_contains((string) @file_get_contents($base, false, $context), 'auth[driver]')) {
		if (time() > $deadline) {
			$server->stop();
			throw new RuntimeException("the app is not answering on port $port, is something else using it?");
		}
		usleep(200_000);
	}
	return compact('root', 'data', 'artifacts', 'server', 'base', 'database', 'driver');
}

/** The fixture for the driver this process is testing, booted once.
 *
 * Every context calls this rather than booting its own: the suites run in one process, and a
 * second server would mean a second data dir, which is the file half the scenarios assert on.
 *
 * @return array<string, mixed>
 */
function e2e_fixture(): array
{
	static $fixtures = [];
	$name = getenv('ADMINER_DESKTOP_E2E_DRIVER') ?: 'pgsql';
	if (!isset($fixtures[$name])) {
		$fixtures[$name] = e2e_boot();
	}
	return $fixtures[$name];
}

/** Get a port nothing is listening on yet, starting from the one asked for. */
function e2e_free_port(int $port): int
{
	for ($i = 0; $i < 20; $i++) {
		$socket = @stream_socket_server('tcp://127.0.0.1:' . ($port + $i), $errno, $error);
		if ($socket) {
			fclose($socket);
			return $port + $i;
		}
	}
	throw new RuntimeException("no free port between $port and " . ($port + 19));
}

/** Build a link into the demo database.
 *
 * @param array<string, mixed> $fix what e2e_boot() returned
 * @param array<string, string> $params e.g. ['select' => 'users']
 */
function e2e_url(array $fix, array $params): string
{
	/** @var array<string, mixed> $driver */
	$driver = $fix['driver'];
	$connection = [
		(string) $driver['key'] => (string) $fix['database'],
		'username' => (string) $driver['username'],
		'db' => E2E_DATABASE,
	];
	if ($driver['name'] === 'pgsql') {
		$connection['ns'] = 'public'; // PostgreSQL addresses tables in a schema
	}
	// array_merge, so a scenario can point somewhere else than the defaults above.
	return $fix['base'] . '?' . http_build_query(array_merge($connection, $params));
}

/** Log a page into the demo database.
 *
 * Verified and retried, because the wait below is a guess: Adminer rebuilds the driver's fields on
 * change, and a value filled in the middle of that is posted empty, which comes back as the login
 * page. Whatever the scenario does next then fails on an empty page, which reads as anything but a
 * login problem — the failure it produced was "the edit form has no fields".
 *
 * @param array<string, mixed> $fix
 */
function e2e_login(PageInterface $page, array $fix): void
{
	/** @var array<string, mixed> $driver */
	$driver = $fix['driver'];
	for ($attempt = 1; $attempt <= 3; $attempt++) {
		$page->goto((string) $fix['base']);
		$page->locator('select[name="auth[driver]"]')->selectOption((string) $driver['key']);
		usleep(400_000 * $attempt); // let the rebuild settle, and wait longer each time round
		$page->locator('input[name="auth[server]"]')->fill((string) $fix['database']);
		$page->locator('input[name="auth[username]"]')->fill((string) $driver['username']);
		$page->locator('input[name="auth[password]"]')->fill((string) $driver['password']);
		$page->locator('input[name="auth[db]"]')->fill(E2E_DATABASE);
		// Submit the form rather than clicking: the rebuild leaves the button intermittently "not
		// actionable", and this depends on neither its markup nor its label.
		$page->evaluate('() => document.querySelector(\'[name="auth[driver]"]\').form.requestSubmit()');
		$page->waitForLoadState('networkidle');
		// A rejected login comes back as the login page, and its title says so. The title is a
		// driver call, unlike an evaluate, which throws when it lands while adminer's answer to a
		// good login is still committing.
		if (!str_starts_with($page->title(), 'Login')) {
			return;
		}
	}
	throw new RuntimeException('could not log in after 3 attempts');
}

/** The browser every scenario opens a context in.
 *
 * One browser for the whole run, one context per scenario: the context is what holds the cookies,
 * so a scenario still logs in on a session nobody else has touched, while the browser and the node
 * process driving it are started once instead of forty times.
 *
 * `make e2e-visual` sets ADMINER_DESKTOP_E2E_HEADED, which shows the browser and slows it down
 * enough to follow — how a scenario is written, and how a failing one is understood.
 */
function e2e_browser(): BrowserInterface
{
	// The client as well as the browser: it closes the connection to the node process when it is
	// collected, and the browser is then talking to nobody.
	static $client = null, $browser = null;
	if (!$browser) {
		$headed = (bool) getenv('ADMINER_DESKTOP_E2E_HEADED');
		// Longer than the 30 seconds Playwright gives an action, and deliberately not equal to it:
		// both sides default to 30, so a step that ran out of time raced the answer against our own
		// giving up on it.
		$client = PlaywrightFactory::create(new PlaywrightConfig(timeoutMs: 60000));
		$browser = $client->chromium()->withHeadless(!$headed)->withSlowMo($headed ? 300 : 0)->launch();
		register_shutdown_function(function () use (&$client): void {
			$client->close();
		});
	}
	return $browser;
}

/** Save the page a step failed on, as a picture and as HTML.
 *
 * A failure in CI is otherwise a line of text about a page nobody can open any more; the workflow
 * uploads this directory. Nothing here may throw — it runs while a scenario is already failing,
 * and its own exception would replace the message saying what went wrong.
 *
 * @param array<string, mixed> $fix
 */
function e2e_report(PageInterface $page, array $fix, string $name): void
{
	try {
		/** @var array<string, mixed> $driver */
		$driver = $fix['driver'];
		$path = "{$fix['artifacts']}/{$driver['name']}-$name";
		$page->screenshot("$path.png");
		file_put_contents("$path.html", $page->content());
	} catch (Throwable $e) {
		fwrite(STDERR, 'could not save the failed page: ' . $e->getMessage() . "\n");
	}
}

/** What the app has persisted, which is what survives a cold start.
 *
 * @param array<string, mixed> $fix
 * @return array<string, mixed>
 */
function e2e_settings(array $fix): array
{
	$file = $fix['data'] . '/settings.json';
	clearstatcache(true, $file);
	if (!is_file($file)) {
		return [];
	}
	$stored = json_decode((string) file_get_contents($file), true);
	return is_array($stored) ? $stored : [];
}
