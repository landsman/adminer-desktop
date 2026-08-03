<?php
declare(strict_types=1);

namespace Desktop\Tests;

require_once dirname(__DIR__) . '/harness/fixture.php';

use Behat\Behat\Context\Context;
use Behat\Hook\BeforeScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use Desktop\Mcp\Handshake;
use Desktop\Mcp\RequestLog;
use Desktop\Mcp\Stdio;
use Desktop\UserSettings;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/** The steps the MCP features are written in.
 *
 * There is no browser here. What is under test is an HTTP session being borrowed by a second
 * process, so the scenarios log in with curl and drive the endpoint exactly as an agent would —
 * over the wire for the endpoint, over stdin and stdout for the bridge.
 *
 * The bridge, the recorded URL and the request log need no app at all: those scenarios build the
 * class with a transport of their own, which is the only way to provoke a closed window or a
 * midnight boundary on demand.
 */
class McpContext implements Context
{

	/** @var array<string, mixed> */
	private array $fix;

	private string $driver;

	/** @var array<string, string> the session an agent borrows */
	private array $cookies = [];

	/** Where the handshake says to post, which is not the same as the login page. */
	private string $mcpUrl = '';

	/** @var array<string, mixed>|null the last JSON-RPC answer, decoded */
	private ?array $answer = null;

	/** @var array<string, mixed>|null what the tool put in it, decoded again out of its text */
	private ?array $payload = null;

	private ?Stdio $bridge = null;

	private ?Handshake $handshake = null;

	/** @var array{url:string, body:string, cookies:array<string, string>}|null what the bridge posted */
	private ?array $posted = null;

	private ?string $raw = null;

	private ?RequestLog $log = null;

	private string $logDir = '';

	public function __construct(string $driver)
	{
		$this->driver = $driver;
	}

	#[BeforeScenario]
	public function open(): void
	{
		putenv("ADMINER_DESKTOP_E2E_DRIVER=$this->driver");
		$this->fix = e2e_fixture();
	}

	// ── the endpoint, against a real window ──────────────────────────────────────────────────

	#[Given('AI access is on')]
	public function accessIsOn(): void
	{
		$this->writeSettings(['mcp' => true]);
		@unlink($this->fix['data'] . '/mcp.json');
	}

	#[Given('AI access is on and allowed to write')]
	public function accessIsOnWithWrites(): void
	{
		$this->writeSettings(['mcp' => true, 'mcp_write' => true]);
	}

	#[When('AI access is turned off')]
	public function accessIsTurnedOff(): void
	{
		$this->writeSettings(['mcp' => false]);
		$this->get((string) $this->fix['base']); // the next request is what retracts it
	}

	/** Log in with curl and keep the cookies — the same borrow the bridge performs. A *connected*
	 * request afterwards, so the handshake records a URL that carries the connection: a bare
	 * adminer.php would reach an adminer with no driver, which is the bug this caught.
	 */
	#[Given('a window is logged in to the demo database')]
	public function windowIsLoggedIn(): void
	{
		/** @var array<string, mixed> $driver */
		$driver = $this->fix['driver'];
		$base = (string) $this->fix['base'];
		$page = $this->get($base);
		preg_match("~name='token' value='([^']*)'~", $page, $token);

		$after = $this->post($base, http_build_query([
			'auth' => [
				'driver' => (string) $driver['key'],
				'server' => (string) $this->fix['database'],
				'username' => (string) $driver['username'],
				'password' => (string) $driver['password'],
				'db' => E2E_DATABASE,
			],
			'token' => $token[1] ?? '',
		]), follow: true);
		if (!str_contains($after, 'Logout') && !str_contains($after, 'logout=1')) {
			throw new RuntimeException('could not log in with curl, the rest of the scenario would be meaningless');
		}
		$this->get(e2e_url($this->fix, ['select' => 'users']));
		// Where the handshake says to post, if there is one: a bare adminer.php would reach an
		// adminer with no driver, and every tool call would die on a null.
		$path = $this->fix['data'] . '/mcp.json';
		clearstatcache(true, $path);
		$handshake = is_file($path) ? (array) json_decode((string) file_get_contents($path), true) : [];
		$this->mcpUrl = is_string($handshake['url'] ?? null) ? $handshake['url'] : $base;
	}

	#[Then('a handshake is written, pointing at this window')]
	public function handshakeIsWritten(): void
	{
		$handshake = $this->readHandshake();
		$url = (string) ($handshake['url'] ?? '');
		$this->mcpUrl = $url !== '' ? $url : (string) $this->fix['base'];
		if (!str_contains($url, (string) parse_url((string) $this->fix['base'], PHP_URL_PORT))) {
			throw new RuntimeException('the handshake url does not point at the running app: ' . json_encode($url));
		}
		if (!str_contains($url, 'db=' . E2E_DATABASE)) {
			throw new RuntimeException("the handshake url lost the connection: $url");
		}
	}

	#[Then('it carries the session to borrow')]
	public function handshakeCarriesSession(): void
	{
		$handshake = $this->readHandshake();
		if (!isset($handshake['cookies']['adminer_sid'])) {
			throw new RuntimeException('the handshake carries no adminer_sid, so nothing could borrow the session');
		}
	}

	#[Then('it is readable only by its owner')]
	public function handshakeIsPrivate(): void
	{
		$this->assertPrivate($this->fix['data'] . '/mcp.json');
	}

	#[Then('the handshake is retracted')]
	public function handshakeIsRetracted(): void
	{
		if (is_file($this->fix['data'] . '/mcp.json')) {
			throw new RuntimeException('the handshake is still on disk after the setting was turned off');
		}
	}

	#[When('an agent initializes')]
	public function agentInitializes(): void
	{
		$this->answer = $this->rpc(['id' => 1, 'method' => 'initialize', 'params' => []]);
	}

	#[When('a client newer than us initializes')]
	public function newerClientInitializes(): void
	{
		$this->answer = $this->rpc([
			'id' => 11,
			'method' => 'initialize',
			'params' => ['protocolVersion' => '2099-01-01'],
		]);
	}

	#[When('a client at :version initializes')]
	public function olderClientInitializes(string $version): void
	{
		$this->answer = $this->rpc([
			'id' => 12,
			'method' => 'initialize',
			'params' => ['protocolVersion' => $version],
		]);
	}

	#[Then('it is answered with a protocol version')]
	public function answeredWithAProtocolVersion(): void
	{
		if (($this->answer['result']['protocolVersion'] ?? '') === '') {
			throw new RuntimeException('initialize returned no protocolVersion: ' . json_encode($this->answer));
		}
	}

	/** A client newer than us has to be told what we actually implement rather than agreeably
	 * echoed, or we promise whatever that revision added.
	 */
	#[Then('it is told our version rather than its own')]
	public function toldOurVersion(): void
	{
		$ours = $this->rpc(['id' => 1, 'method' => 'initialize', 'params' => []])['result']['protocolVersion'] ?? '';
		if (($this->answer['result']['protocolVersion'] ?? '') !== $ours) {
			throw new RuntimeException('a newer client was echoed its own version: '
				. json_encode($this->answer['result']['protocolVersion'] ?? null));
		}
	}

	#[Then('it is met at :version')]
	public function metAtVersion(string $version): void
	{
		if (($this->answer['result']['protocolVersion'] ?? '') !== $version) {
			throw new RuntimeException('an older client was not met at its own version: '
				. json_encode($this->answer['result']['protocolVersion'] ?? null));
		}
	}

	#[When('an agent lists the tools')]
	public function agentListsTools(): void
	{
		$this->answer = $this->rpc(['id' => 2, 'method' => 'tools/list', 'params' => []]);
	}

	#[Then('the tools offered include :names')]
	public function toolsInclude(string $names): void
	{
		$offered = array_column((array) ($this->answer['result']['tools'] ?? []), 'name');
		foreach (array_map('trim', explode(',', $names)) as $want) {
			if (!in_array($want, $offered, true)) {
				throw new RuntimeException("tools/list is missing $want, it offers " . implode(', ', $offered));
			}
		}
	}

	#[When('an agent calls :tool')]
	public function agentCallsTool(string $tool): void
	{
		$this->answer = $this->rpc(['id' => 3, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => []]]);
	}

	#[When('an agent runs the query :sql')]
	public function agentRunsQuery(string $sql): void
	{
		$this->answer = $this->rpc([
			'id' => 4,
			'method' => 'tools/call',
			'params' => ['name' => 'execute_query', 'arguments' => ['sql' => $sql]],
		]);
		// The tool's payload is JSON inside a JSON string, so it is decoded twice — a naive
		// contains('"rolled_back"') looks for a quote that is escaped by the time it reaches here.
		$this->payload = (array) json_decode((string) ($this->answer['result']['content'][0]['text'] ?? ''), true);
	}

	#[Then('the answer mentions :text')]
	public function answerMentions(string $text): void
	{
		$said = (string) ($this->answer['result']['content'][0]['text'] ?? '');
		if (!str_contains($said, $text)) {
			throw new RuntimeException("the answer does not mention $text: " . substr($said, 0, 200));
		}
	}

	/** RETURNING makes an INSERT produce an ordinary result set, so without this the caller sees
	 * rows and concludes it wrote — which is exactly what happened in use, and is worse than
	 * refusing the write outright.
	 */
	#[Then('the answer says it was rolled back')]
	public function answerSaysRolledBack(): void
	{
		if (($this->payload['rolled_back'] ?? null) !== true) {
			throw new RuntimeException('a write answered without saying it was rolled back: ' . json_encode($this->payload));
		}
	}

	#[Then('the answer no longer claims a rollback')]
	public function answerDoesNotClaimRollback(): void
	{
		if (($this->payload['rolled_back'] ?? null) !== false) {
			throw new RuntimeException('with writes on the answer still claims a rollback: ' . json_encode($this->payload));
		}
	}

	/** Prove the statement is well-formed first, or "no row survived" would pass just as happily
	 * for one that never ran at all. Straight at the database, then taken back out.
	 */
	#[Given('the same statement lands when it is run straight at the database')]
	public function statementLandsDirectly(): void
	{
		$probe = 'mcp-probe-direct';
		$landed = e2e_sql(
			(array) $this->fix['driver'],
			"INSERT INTO users (name) VALUES ('$probe'); SELECT count(*) FROM users WHERE name = '$probe'",
		);
		$lines = array_values(array_filter(array_map('trim', explode("\n", $landed)), 'strlen'));
		if (end($lines) !== '1') {
			throw new RuntimeException("the probe INSERT is not valid SQL, so the rollback assertion proves nothing: $landed");
		}
		e2e_sql((array) $this->fix['driver'], "DELETE FROM users WHERE name = '$probe'");
	}

	#[Then('no row named :marker survived')]
	public function noRowSurvived(string $marker): void
	{
		$left = e2e_sql((array) $this->fix['driver'], "SELECT count(*) FROM users WHERE name LIKE '$marker%'");
		if ($left !== '0') {
			throw new RuntimeException("READ-ONLY BROKEN: the write survived, $left row(s) named $marker");
		}
	}

	#[Then('the row named :marker is in the database')]
	public function rowIsInTheDatabase(string $marker): void
	{
		$found = e2e_sql((array) $this->fix['driver'], "SELECT count(*) FROM users WHERE name = '$marker'");
		e2e_sql((array) $this->fix['driver'], "DELETE FROM users WHERE name = '$marker'");
		if ($found !== '1') {
			throw new RuntimeException("writes were enabled but the row did not persist (found $found)");
		}
	}

	/** The shim as an agent runs it: a bare child process, over its stdin and stdout. */
	#[When('an agent runs :sql through the stdio bridge')]
	public function throughTheStdioBridge(string $sql): void
	{
		$shim = new Process(
			[$this->fix['root'] . '/bin/frankenphp', 'php-cli', $this->fix['root'] . '/app/mcp.php'],
			null,
			['ADMINER_DESKTOP_DATA' => $this->fix['data']],
		);
		$shim->setInput(implode("\n", [
			(string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => []]),
			(string) json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => [
				'name' => 'execute_query',
				'arguments' => ['sql' => $sql],
			]]),
		]) . "\n");
		$shim->run();
		$this->raw = $shim->getOutput() . $shim->getErrorOutput();
		$this->payload = null;
		foreach (explode("\n", trim($shim->getOutput())) as $line) {
			$message = (array) json_decode($line, true);
			if (($message['id'] ?? null) === 2) {
				$this->payload = (array) json_decode((string) ($message['result']['content'][0]['text'] ?? ''), true);
			}
		}
	}

	#[Then('the bridge answered the handshake')]
	public function bridgeAnsweredHandshake(): void
	{
		if (!str_contains((string) $this->raw, 'protocolVersion')) {
			throw new RuntimeException('the stdio bridge did not answer initialize: ' . substr((string) $this->raw, 0, 300));
		}
	}

	#[Then('it returned the counted row')]
	public function bridgeReturnedTheRow(): void
	{
		$rows = $this->payload['rows'] ?? null;
		if (!is_array($rows) || count($rows) !== 1 || !isset($rows[0]['n'])) {
			throw new RuntimeException('the bridge did not return the counted row: ' . substr((string) $this->raw, 0, 300));
		}
	}

	// ── the bridge, with no app behind it ────────────────────────────────────────────────────

	/** An agent spawns the bridge as a bare child process, so if finding the data dir ever depends
	 * on our own environment again it reports the app as not running while it is open in front of
	 * you — which is what it did, and the end-to-end scenario cannot see it, because that one sets
	 * the variable itself.
	 */
	#[Then('the bridge finds the data dir with no environment of ours')]
	public function bridgeFindsDataDir(): void
	{
		$was = getenv('ADMINER_DESKTOP_DATA');
		putenv('ADMINER_DESKTOP_DATA');
		$found = (new Handshake())->path();
		if ($was !== false) {
			putenv("ADMINER_DESKTOP_DATA=$was");
		}
		if ($found === null) {
			throw new RuntimeException('with no ADMINER_DESKTOP_DATA the bridge finds no data dir at all');
		}
		if (!str_ends_with($found, 'Adminer Desktop/mcp.json')) {
			throw new RuntimeException("the fallback data dir is not the launcher's: $found");
		}
	}

	#[Given('the app is not running')]
	public function appIsNotRunning(): void
	{
		$this->handshake = new Handshake($this->scratch());
		$this->handshake->clear();
		$this->bridge = new Stdio($this->handshake, fn (): bool => false);
	}

	#[Given('the window was closed')]
	public function windowWasClosed(): void
	{
		$this->handshake = new Handshake($this->scratch());
		$this->handshake->write('http://127.0.0.1:1/adminer.php?pgsql=x&db=demo&', ['adminer_sid' => 'abc']);
		$this->bridge = new Stdio($this->handshake, fn (): bool => false);
	}

	#[Given('the app answers with :what')]
	public function appAnswersWith(string $what): void
	{
		$this->handshake = new Handshake($this->scratch());
		$this->handshake->write('http://127.0.0.1:1/adminer.php?pgsql=x&db=demo&', ['adminer_sid' => 'abc']);
		$answers = [
			'nothing' => '',
			'a login page' => '<!doctype html><title>Login</title>',
			'a json answer' => '{"jsonrpc":"2.0","id":7,"result":{"tools":[]}}',
		];
		if (!isset($answers[$what])) {
			throw new RuntimeException("no such answer to stub: $what");
		}
		$this->raw = $answers[$what];
		$this->bridge = new Stdio($this->handshake, fn (): string => $answers[$what]);
	}

	#[When('a client initializes through the bridge')]
	public function initializeThroughBridge(): void
	{
		$this->answer = (array) json_decode(
			(string) $this->bridge?->exchange((string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize'])),
			true,
		);
	}

	#[When('a client lists the tools through the bridge')]
	public function listToolsThroughBridge(): void
	{
		$this->raw = $this->bridge?->exchange($this->request());
		$this->answer = (array) json_decode((string) $this->raw, true);
	}

	#[When('a client calls a tool through the bridge')]
	public function callToolThroughBridge(): void
	{
		$this->answer = (array) json_decode((string) $this->bridge?->exchange((string) json_encode(
			['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'list_tables']],
		)), true);
	}

	#[When('a notification arrives through the bridge')]
	public function notificationThroughBridge(): void
	{
		$this->raw = $this->bridge?->exchange((string) json_encode(
			['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
		));
	}

	/** Failing initialize is reported by clients as a dead server: they print the JSON-RPC code and
	 * drop the message, so the sentence saying what to do never arrives.
	 */
	#[Then('initialize still succeeds')]
	public function initializeStillSucceeds(): void
	{
		if (($this->answer['result']['protocolVersion'] ?? null) === null) {
			throw new RuntimeException('initialize failed while the app was unavailable: ' . json_encode($this->answer));
		}
	}

	/** Not an empty list: "connected, no tools" leaves nothing to call, so the reason can never be
	 * reached. The one tool advertised is the reason, readable in the client's own tool list.
	 */
	#[Then('exactly one tool is advertised, named :name')]
	public function oneToolAdvertised(string $name): void
	{
		$tools = (array) ($this->answer['result']['tools'] ?? []);
		if (count($tools) !== 1) {
			throw new RuntimeException(count($tools) . ' tools are advertised while unavailable, not one');
		}
		if (($tools[0]['name'] ?? null) !== $name) {
			throw new RuntimeException('the tool advertised is ' . json_encode($tools[0]['name'] ?? null));
		}
	}

	#[Then('it is described with what to do about it')]
	public function describedWithWhatToDo(): void
	{
		$description = (string) ($this->answer['result']['tools'][0]['description'] ?? '');
		if (!str_contains($description, 'AI access')) {
			throw new RuntimeException("the description does not say what to do: $description");
		}
	}

	/** The explanation belongs where a client renders it: a tool result, not a transport error. */
	#[Then('the call is a tool error saying :text')]
	public function callIsToolError(string $text): void
	{
		if (($this->answer['result']['isError'] ?? null) !== true) {
			throw new RuntimeException('a call while unavailable was not a tool error: ' . json_encode($this->answer));
		}
		$said = (string) ($this->answer['result']['content'][0]['text'] ?? '');
		if (!str_contains($said, $text)) {
			throw new RuntimeException("it does not say $text: $said");
		}
	}

	#[Then('the answer says :text')]
	public function answerSays(string $text): void
	{
		$said = (string) ($this->answer['result']['content'][0]['text'] ?? '');
		if (!str_contains($said, $text)) {
			throw new RuntimeException("the answer does not say $text: $said");
		}
	}

	/** JSON-RPC answers a notification with silence; an error here is a protocol violation strict
	 * clients complain about.
	 */
	#[Then('nothing is sent back')]
	public function nothingIsSentBack(): void
	{
		if ($this->raw !== null) {
			throw new RuntimeException('something was sent back: ' . json_encode($this->raw));
		}
	}

	#[Then('it is forwarded unchanged')]
	public function forwardedUnchanged(): void
	{
		$sent = (string) $this->bridge?->exchange($this->request());
		if ($sent !== '{"jsonrpc":"2.0","id":7,"result":{"tools":[]}}') {
			throw new RuntimeException("the bridge reshaped what the server said: $sent");
		}
	}

	/** The URL it posts to carries the connection from the handshake, plus mcp=1 — the bug that
	 * made every tool call reach an adminer with no driver.
	 */
	#[When('a client asks the bridge for anything')]
	public function bridgeIsAskedForAnything(): void
	{
		$this->handshake = new Handshake($this->scratch());
		$this->handshake->write('http://127.0.0.1:1/adminer.php?pgsql=x&db=demo&', ['adminer_sid' => 'abc']);
		$seen = null;
		$spy = new Stdio($this->handshake, function (string $url, string $body, array $cookies) use (&$seen): string {
			$seen = ['url' => $url, 'body' => $body, 'cookies' => $cookies];
			return '{}';
		});
		$spy->exchange($this->request());
		/** @var array{url:string, body:string, cookies:array<string, string>}|null $seen */
		$this->posted = $seen;
	}

	#[Then('it posts to the connected url with mcp=1, replaying the session cookie')]
	public function postsToConnectedUrl(): void
	{
		$posted = (array) $this->posted;
		foreach (['mcp=1', 'db=demo'] as $needle) {
			if (!str_contains((string) ($posted['url'] ?? ''), $needle)) {
				throw new RuntimeException("the bridge posted to " . json_encode($posted['url'] ?? null) . ", with no $needle");
			}
		}
		if (($posted['body'] ?? null) !== $this->request()) {
			throw new RuntimeException('the bridge changed the message on the way through');
		}
		if ((((array) ($posted['cookies'] ?? []))['adminer_sid'] ?? null) !== 'abc') {
			throw new RuntimeException('the bridge did not replay the session cookie');
		}
	}

	/** run() pumps a stream: blank lines skipped, one answer per request, silence stays silent. A
	 * stub that answered every message alike would prove nothing about the silence, so this one
	 * answers like the app — a notification with an empty body.
	 */
	#[Then('the bridge answers each request once and every notification never')]
	public function bridgePumpsTheStream(): void
	{
		$handshake = new Handshake($this->scratch());
		$handshake->write('http://127.0.0.1:1/adminer.php?pgsql=x&db=demo&', ['adminer_sid' => 'abc']);
		$answer = '{"jsonrpc":"2.0","id":7,"result":{"tools":[]}}';
		$in = fopen('php://memory', 'r+');
		fwrite($in, $this->request() . "\n\n"
			. (string) json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']) . "\n"
			. $this->request() . "\n");
		rewind($in);
		$out = fopen('php://memory', 'r+');
		(new Stdio($handshake, fn (string $url, string $body): string
			=> isset(((array) json_decode($body, true))['id']) ? $answer : ''))->run($in, $out);
		rewind($out);
		$written = (string) stream_get_contents($out);
		if (substr_count($written, '"result"') !== 2 || substr_count($written, "\n") !== 2) {
			throw new RuntimeException("the stream was answered as: $written");
		}
	}

	// ── the recorded url ─────────────────────────────────────────────────────────────────────

	/** @var array<string, mixed> the server variables a scenario is asking about */
	private array $server = [];

	private string $me = 'adminer.php?pgsql=127.0.0.1%3A55432&username=postgres&db=demo&';

	#[When('the app records where it is, served from :host at :script')]
	public function recordUrl(string $host, string $script): void
	{
		$this->server = $host === 'nowhere' ? ['SCRIPT_NAME' => $script] : ['HTTP_HOST' => $host, 'SCRIPT_NAME' => $script];
	}

	#[Then('the url recorded while connected is :expected')]
	public function urlRecordedIs(string $expected): void
	{
		$url = (new \Desktop\Mcp\Endpoint(new UserSettings(null)))->url($this->server, true, $this->me);
		$want = str_replace('<me>', $this->me, $expected);
		if ($url !== $want) {
			throw new RuntimeException('the recorded url is ' . json_encode($url));
		}
	}

	/** Not connected is not logged in: there is nothing worth borrowing, so nothing is recorded. */
	#[Then('nothing is recorded while disconnected')]
	public function nothingRecordedDisconnected(): void
	{
		$url = (new \Desktop\Mcp\Endpoint(new UserSettings(null)))->url($this->server, false, $this->me);
		if ($url !== null) {
			throw new RuntimeException('something was recorded while disconnected: ' . json_encode($url));
		}
	}

	#[Then('nothing is recorded at all')]
	public function nothingRecorded(): void
	{
		$url = (new \Desktop\Mcp\Endpoint(new UserSettings(null)))->url($this->server, true, $this->me);
		if ($url !== null) {
			throw new RuntimeException('something was recorded with nothing to build it from: ' . json_encode($url));
		}
	}

	/** Falling through would reach the server and crash on a null driver, and answering HTML would
	 * leave the agent parsing a login page.
	 */
	#[Then('a request on a stale handshake is told to log in again')]
	public function staleHandshakeIsAnswered(): void
	{
		$answer = (new \Desktop\Mcp\Endpoint(new UserSettings(null)))
			->answer('{"jsonrpc":"2.0","id":1,"method":"tools/list"}', false);
		$decoded = (array) json_decode((string) $answer, true);
		if (($decoded['error']['code'] ?? null) !== -32000) {
			throw new RuntimeException('a stale handshake was not answered with a json-rpc error: ' . json_encode($answer));
		}
		if (!str_contains((string) ($decoded['error']['message'] ?? ''), 'Log in again')) {
			throw new RuntimeException('the message does not say what to do: ' . json_encode($answer));
		}
	}

	// ── the request log ──────────────────────────────────────────────────────────────────────

	/** Rotation and retention only misbehave on a date boundary, which is not something a run can
	 * wait for — so the day is an argument and the boundary is just a number.
	 */
	#[Given('a log directory of its own')]
	public function aLogDirectory(): void
	{
		$this->logDir = $this->scratch();
		$this->log = new RequestLog($this->logDir);
	}

	#[When('a request is logged on day :day')]
	public function requestIsLogged(string $day): void
	{
		$this->log?->append('tools/call', 'execute_query', "SELECT *\n  FROM users", $this->day($day));
	}

	#[When('a second request is logged on day :day')]
	public function secondRequestIsLogged(string $day): void
	{
		$this->log?->append('tools/list', '', '', $this->day($day));
	}

	#[Then('day :day has its own file, named for the date')]
	public function dayHasItsOwnFile(string $day): void
	{
		$path = (string) $this->log?->path($this->day($day));
		$want = 'mcp-' . gmdate('Y-m-d', $this->day($day)) . '.log';
		if (basename($path) !== $want) {
			throw new RuntimeException("the file is named " . basename($path) . ", not $want");
		}
	}

	#[Then('day :day holds :count lines')]
	public function dayHoldsLines(string $day, int $count): void
	{
		$path = (string) $this->log?->path($this->day($day));
		$lines = is_file($path) ? (array) file($path, FILE_IGNORE_NEW_LINES) : [];
		if (count($lines) !== $count) {
			throw new RuntimeException('day ' . $day . ' holds ' . count($lines) . " lines, not $count");
		}
	}

	/** Newlines in SQL must not become extra lines, or one call reads as several. */
	#[Then('the line names the method, the tool and the query on one line')]
	public function lineIsTabSeparated(): void
	{
		$path = (string) $this->log?->path($this->day('1'));
		$lines = (array) file($path, FILE_IGNORE_NEW_LINES);
		$fields = explode("\t", (string) ($lines[0] ?? ''));
		if (count($fields) !== 4) {
			throw new RuntimeException('the line has ' . count($fields) . ' fields, not four: ' . json_encode($lines[0] ?? null));
		}
		if ($fields[1] !== 'tools/call' || $fields[2] !== 'execute_query' || $fields[3] !== 'SELECT * FROM users') {
			throw new RuntimeException('the line reads ' . json_encode($fields));
		}
	}

	#[Given('a file from :days days before day :day')]
	public function anOldFile(int $days, string $day): void
	{
		$path = $this->logDir . '/mcp-' . gmdate('Y-m-d', $this->day($day) - ($days * 86400)) . '.log';
		file_put_contents($path, "old\n");
	}

	#[Then('files older than the window are gone and recent ones are kept')]
	public function oldFilesArePruned(): void
	{
		$old = $this->logDir . '/mcp-' . gmdate('Y-m-d', $this->day('2') - (20 * 86400)) . '.log';
		$recent = $this->logDir . '/mcp-' . gmdate('Y-m-d', $this->day('2') - (5 * 86400)) . '.log';
		if (is_file($old)) {
			throw new RuntimeException('a file beyond the window survived');
		}
		if (!is_file($recent)) {
			throw new RuntimeException('a file inside the window was pruned');
		}
	}

	#[Then('the log is readable only by its owner')]
	public function logIsPrivate(): void
	{
		$this->assertPrivate((string) $this->log?->path($this->day('2')));
	}

	/** Served without the launcher there is no directory, and that is silence rather than a crash. */
	#[Then('a log with nowhere to write is silent rather than broken')]
	public function logWithNoDirectory(): void
	{
		$none = new RequestLog(null);
		if ($none->path($this->day('1')) !== null) {
			throw new RuntimeException('a log with no directory offered a path anyway');
		}
		$none->append('ping', '', '', $this->day('1')); // must not throw
	}

	// ── the plumbing ─────────────────────────────────────────────────────────────────────────

	/** @param array<string, mixed> $settings */
	private function writeSettings(array $settings): void
	{
		file_put_contents($this->fix['data'] . '/settings.json', (string) json_encode($settings));
	}

	/** @return array<string, mixed> */
	private function readHandshake(): array
	{
		$path = $this->fix['data'] . '/mcp.json';
		clearstatcache(true, $path);
		if (!is_file($path)) {
			throw new RuntimeException('no handshake was written after a connected request');
		}
		return (array) json_decode((string) file_get_contents($path), true);
	}

	/** It holds session cookies, and a log names tables and queries: neither may be world-readable. */
	private function assertPrivate(string $path): void
	{
		$perms = substr(sprintf('%o', (int) fileperms($path)), -3);
		if ($perms !== '600') {
			throw new RuntimeException(basename($path) . " is $perms, expected 600");
		}
	}

	/** One JSON-RPC message straight at the app, with the session's cookies.
	 * @param array<string, mixed> $message
	 * @return array<string, mixed>
	 */
	private function rpc(array $message): array
	{
		$url = $this->mcpUrl . (str_contains($this->mcpUrl, '?') ? '&' : '?') . 'mcp=1';
		return (array) json_decode($this->post($url, (string) json_encode(['jsonrpc' => '2.0'] + $message), json: true), true);
	}

	private function request(): string
	{
		return (string) json_encode(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/list']);
	}

	private function get(string $url): string
	{
		return $this->curl($url, null, false, false);
	}

	private function post(string $url, string $body, bool $follow = false, bool $json = false): string
	{
		return $this->curl($url, $body, $follow, $json);
	}

	private function curl(string $url, ?string $body, bool $follow, bool $json): string
	{
		$ch = curl_init($url);
		$options = [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_COOKIEFILE => '',
			CURLOPT_FOLLOWLOCATION => $follow,
			CURLOPT_COOKIE => implode('; ', array_map(
				fn (string $k, string $v): string => "$k=$v",
				array_keys($this->cookies),
				$this->cookies,
			)),
		];
		if ($body !== null) {
			$options[CURLOPT_POST] = true;
			$options[CURLOPT_POSTFIELDS] = $body;
		}
		if ($json) {
			$options[CURLOPT_HTTPHEADER] = ['Content-Type: application/json'];
		}
		curl_setopt_array($ch, $options);
		$answer = (string) curl_exec($ch);
		foreach ((array) curl_getinfo($ch, CURLINFO_COOKIELIST) as $line) {
			$fields = explode("\t", (string) $line);
			if (count($fields) >= 7) {
				$this->cookies[$fields[5]] = $fields[6];
			}
		}
		return $answer;
	}

	/** A directory of this scenario's own, cleaned up when the process ends.
	 *
	 * Counted, not merely named after the process: the log scenarios each write a file per day into
	 * it, and a directory shared with the scenario before reads as yesterday's lines surviving into
	 * today — which is one of the things they are here to assert.
	 */
	private function scratch(): string
	{
		static $nth = 0;
		$dir = sys_get_temp_dir() . '/adminer-desktop-mcp-' . getmypid() . '-' . ++$nth;
		@mkdir($dir, 0700, true);
		register_shutdown_function(static function () use ($dir): void {
			try {
				array_map('unlink', (array) glob("$dir/*"));
				@rmdir($dir);
			} catch (Throwable $e) {
				// a leftover temp directory is not worth failing a run over
			}
		});
		return $dir;
	}

	/** The two days the log scenarios talk about, as timestamps. */
	private function day(string $day): int
	{
		$first = mktime(12, 0, 0, 8, 3, 2026);
		return (int) $first + ((int) $day - 1) * 86400;
	}
}
