<?php
declare(strict_types=1);

namespace Desktop\Tests;

require_once dirname(__DIR__) . '/harness/fixture.php';

use Behat\Behat\Context\Context;
use Behat\Hook\AfterScenario;
use Behat\Hook\BeforeScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use Desktop\Mcp\Handshake;
use Desktop\Mcp\Tools;
use Desktop\UserSettings;
use RuntimeException;
use Symfony\Component\Process\Process;

/** The steps the MCP features are written in.
 *
 * There is no browser here. What is under test is an HTTP session being borrowed by a second
 * process, so the scenarios log in with curl and drive the endpoint exactly as an agent would —
 * over the wire for the endpoint, over stdin and stdout for the bridge.
 *
 * The bridge, the recorded URL and the request log need no app at all: the bridge scenarios spawn
 * Bridge — PHP's, or the Go one when ADMINER_DESKTOP_MCP_BRIDGE names it — and point it at an
 * HTTP stub that answers a login page or an empty body on demand, which is the only way to
 * provoke a closed window or a midnight boundary. The rest talk to the real app through the
 * same bridge and the gate, so switching the variable changes the implementation under every MCP
 * scenario; `mise run e2e-bridge-go` runs them all with it set.
 */
class McpContext implements Context
{

	/** @var array<string, mixed> */
	private array $fix;

	private string $driver;

	/** @var array<string, string> the session an agent borrows */
	private array $cookies = [];

	/** @var array<string, mixed>|null the last JSON-RPC answer, decoded */
	private ?array $answer = null;

	/** @var array<string, mixed>|null what the tool put in it, decoded again out of its text */
	private ?array $payload = null;

	private ?Bridge $bridge = null;

	private ?Handshake $handshake = null;

	/** The app the bridge forwards to, when a scenario needs one that answers oddly. */
	private ?Process $app = null;

	private string $appRecord = '';

	/** The socket the stub listens on, which the handshake names as the gate's. */
	private string $appSocket = '';

	/** @var array{url:string, body:string, token:string}|null what the bridge posted */
	private ?array $posted = null;

	private ?string $raw = null;

	/** Whether the scenario changed the reader's password, so it is put back for the next one. */
	private bool $readerBroken = false;

	/** Whether the scenario gave the agent's role a statement limit, so it is taken off again. */
	private bool $agentLimited = false;

	/** The answer to the last request that named a host of its own. */
	private int $status = 0;

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

	// ── the server's own door ────────────────────────────────────────────────────────────────

	#[When('a request for :path names the host :host')]
	public function requestNamesHost(string $path, string $host): void
	{
		$port = parse_url((string) $this->fix['base'], PHP_URL_PORT);
		$ch = curl_init("http://127.0.0.1:$port$path");
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER => ["Host: $host:$port"],
		]);
		curl_exec($ch);
		$this->status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
	}

	#[Then('the server answers :status')]
	public function serverAnswers(int $status): void
	{
		if ($this->status !== $status) {
			throw new RuntimeException("expected $status, got $this->status");
		}
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

	/** Labelled the way the start screen's edit dialog stores it, under the login's key. */
	#[Given('AI access is on and allowed to write, but the connection is marked prod')]
	public function accessIsOnWithWritesOnProd(): void
	{
		$key = implode('|', ['pgsql', (string) $this->fix['database'], E2E_AGENT]);
		$this->writeSettings(['mcp' => true, 'mcp_write' => true, 'connections' => [$key => ['env' => 'prod']]]);
	}

	#[Given('AI access is on and set to return whole values')]
	public function accessIsOnWithWholeValues(): void
	{
		$this->writeSettings(['mcp' => true, 'mcp_whole_values' => true]);
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
		$this->logIn(E2E_AGENT, E2E_DATABASE);
		$this->get(e2e_url($this->fix, ['username' => E2E_AGENT, 'select' => 'users']));
	}

	/** Logged in to the server but into no database: the window an agent once found itself
	 * querying `postgres` through, with current_connection answering an empty name. */
	#[Given('the window is logged in with no database open')]
	public function windowHasNoDatabase(): void
	{
		$this->logIn(E2E_AGENT, '');
		$this->get(e2e_url($this->fix, ['username' => E2E_AGENT, 'db' => null, 'ns' => null]));
	}

	/** The seed's SELECT-only login, into its own database: the login the docs tell you to give
	 * an agent, and the one to prove least privilege against. */
	#[Given('the window is logged in as the SELECT-only reader instead')]
	public function windowIsReader(): void
	{
		$this->logIn(E2E_READER, E2E_SECOND_DATABASE);
	}

	/** A second saved login in the window's session, then broken behind its back: with the
	 * reader's password changed, the saved one fails to connect as it would on a server that went
	 * down. The window goes back to the demo database, so a call naming the reader is forwarded. */
	#[Given('the window is also signed in as the reader, whose password then changes')]
	public function readerSignedInThenBroken(): void
	{
		$this->logIn(E2E_READER, E2E_SECOND_DATABASE);
		$this->windowIsLoggedIn();
		$this->readerBroken = true;
		e2e_sql((array) $this->fix['driver'], 'ALTER ROLE ' . E2E_READER . " PASSWORD 'changed'");
	}

	/** A limit the DBA set for the login: ours must not loosen it. The role's setting applies to a
	 * new session, which is every request. */
	#[Given('the agent\'s login already stops statements after :seconds seconds')]
	public function agentLoginIsLimited(int $seconds): void
	{
		$this->agentLimited = true;
		e2e_sql((array) $this->fix['driver'], 'ALTER ROLE ' . E2E_AGENT . " SET statement_timeout = '{$seconds}s'");
	}

	#[AfterScenario]
	public function restoreReader(): void
	{
		$this->bridge?->close();
		$this->bridge = null;
		$this->app?->stop();
		$this->app = null;
		if ($this->agentLimited) {
			$this->agentLimited = false;
			e2e_sql((array) $this->fix['driver'], 'ALTER ROLE ' . E2E_AGENT . ' RESET statement_timeout');
		}
		if ($this->readerBroken) {
			$this->readerBroken = false;
			e2e_sql((array) $this->fix['driver'], 'ALTER ROLE ' . E2E_READER . " PASSWORD '" . $this->fix['driver']['password'] . "'");
		}
	}

	/** The fixture's own login, postgres: a superuser, the login the agent tools refuse. */
	#[Given('the window is logged in as the superuser instead')]
	public function windowIsSuperuser(): void
	{
		$this->logIn((string) $this->fix['driver']['username'], E2E_DATABASE);
	}

	/** At the gate, not at the app: the app's own port would take the bridge's call with no
	 * session behind it, and the gate is what adds one. */
	#[Then('a handshake is written, pointing at this window through the gate')]
	public function handshakeIsWritten(): void
	{
		$url = (string) ($this->readHandshake()['url'] ?? '');
		$app = (string) parse_url((string) $this->fix['base'], PHP_URL_PORT);
		if (parse_url($url, PHP_URL_HOST) !== '127.0.0.1' || (string) parse_url($url, PHP_URL_PORT) === $app) {
			throw new RuntimeException('the handshake url does not point at the gate: ' . json_encode($url));
		}
		if (!str_contains($url, 'db=' . E2E_DATABASE)) {
			throw new RuntimeException("the handshake url lost the connection: $url");
		}
		// The bridge posts to the socket, which a quit app's port cannot be taken over to fake.
		$socket = (string) ($this->readHandshake()['socket'] ?? '');
		if ($socket === '' || filetype($socket) !== 'socket') {
			throw new RuntimeException('the handshake names no gate socket: ' . json_encode($socket));
		}
	}

	/** A web page can post to loopback too; it cannot read mcp.json, so it has no token. And the
	 * token must not open a page of adminer with the window's session behind it: ?download=
	 * answers before the hook that answers ?mcp=1, so mcp=1 in the query proves nothing. */
	#[Then('the gate refuses a call without the token, and with it opens nothing but the MCP endpoint')]
	public function gateRefuses(): void
	{
		$handshake = $this->readHandshake();
		$url = (string) $handshake['url'];
		$token = (string) $handshake['token'];
		$cases = [
			'no token' => [$url . '&mcp=1', ''],
			'a wrong token' => [$url . '&mcp=1', str_repeat('0', strlen($token))],
		];
		foreach ($cases as $what => [$to, $bearer]) {
			$this->postToGate($to, $bearer);
			$status = (string) (http_get_last_response_headers()[0] ?? '');
			if (!str_contains($status, ' 403 ')) {
				throw new RuntimeException("the gate let through $what: $status");
			}
		}
		// The same download straight at the app, with the session, is what proves the request
		// would hand out a column if the gate passed its path on.
		$download = e2e_url($this->fix, ['username' => E2E_AGENT, 'download' => 'users', 'field' => 'email', 'where' => ['id' => 1]]);
		if (!str_contains($this->get($download), 'anna@example.com')) {
			throw new RuntimeException('the download reads no column even with the session, so this proves nothing');
		}
		$app = '127.0.0.1:' . parse_url((string) $this->fix['base'], PHP_URL_PORT);
		$gate = '127.0.0.1:' . parse_url($url, PHP_URL_PORT);
		$answer = $this->postToGate(str_replace($app, $gate, $download) . '&mcp=1', $token);
		if (str_contains($answer, 'anna@example.com') || !str_starts_with($answer, '{')) {
			throw new RuntimeException('the gate opened a page of adminer with the token: ' . substr($answer, 0, 200));
		}
	}

	private function postToGate(string $url, string $token): string
	{
		$context = stream_context_create(['http' => [
			'method' => 'POST',
			'header' => ['Content-Type: application/json', "Authorization: Bearer $token"],
			'content' => $this->request(),
			'ignore_errors' => true,
		]]);
		return (string) @file_get_contents($url, false, $context);
	}

	/** Adminer keeps the passwords in the session, encrypted with the adminer_key cookie, so the
	 * session id and that key are exactly what must never be in this file: the agent runs as its
	 * owner, and 0600 does not keep it out. Checked against the values this session really holds,
	 * not field names — a renamed field would carry them just the same.
	 */
	#[Then('it carries nothing that unlocks a password')]
	public function handshakeHoldsNoSecret(): void
	{
		$path = $this->fix['data'] . '/mcp.json';
		clearstatcache(true, $path);
		$raw = (string) @file_get_contents($path);
		$keys = array_keys((array) json_decode($raw, true));
		sort($keys);
		// The socket is a path, and only opens the gate the token already opens.
		if ($keys !== ['socket', 'target', 'token', 'url']) {
			throw new RuntimeException('the handshake holds more than url, socket, token and target: ' . json_encode($keys));
		}
		foreach (['adminer_sid', 'adminer_key', 'adminer_permanent'] as $name) {
			$value = rawurldecode($this->cookies[$name] ?? '');
			if ($value === '' && $name !== 'adminer_permanent') {
				throw new RuntimeException("this session has no $name, so the check proves nothing");
			}
			if ($value !== '' && str_contains($raw, $value)) {
				throw new RuntimeException("the handshake carries $name, which unlocks the passwords in the session");
			}
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

	#[Then('the description of :tool mentions :text')]
	public function descriptionMentions(string $tool, string $text): void
	{
		foreach ((array) ($this->answer['result']['tools'] ?? []) as $offered) {
			if (($offered['name'] ?? null) === $tool) {
				if (!str_contains((string) ($offered['description'] ?? ''), $text)) {
					throw new RuntimeException("$tool does not say $text: " . ($offered['description'] ?? ''));
				}
				return;
			}
		}
		throw new RuntimeException("tools/list does not offer $tool");
	}

	#[When('an agent calls :tool')]
	public function agentCallsTool(string $tool): void
	{
		$this->answer = $this->rpc(['id' => 3, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => []]]);
	}

	#[When('an agent calls :tool on database :database')]
	public function agentCallsToolOnDatabase(string $tool, string $database): void
	{
		$this->callWith($tool, ['database' => $database]);
	}

	#[When('an agent calls :tool on connection :connection')]
	public function agentCallsToolOnConnection(string $tool, string $connection): void
	{
		$this->callWith($tool, ['connection' => $connection]);
	}

	#[When('an agent calls :tool on the reader\'s connection')]
	public function agentCallsToolOnReader(string $tool): void
	{
		$this->callWith($tool, ['connection' => $this->readerKey()]);
	}

	private function readerKey(): string
	{
		return implode('|', ['pgsql', (string) $this->fix['database'], E2E_READER]);
	}

	/** @param array<string, string> $arguments */
	private function callWith(string $tool, array $arguments): void
	{
		$this->answer = $this->rpc(['id' => 3, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $arguments]]);
	}

	/** The key list_connections hands out is the one the other tools take, so it has to be the
	 * login's own, signed in. */
	#[Then('the window\'s connection is listed, signed in')]
	public function windowConnectionListed(): void
	{
		$key = implode('|', ['pgsql', (string) $this->fix['database'], E2E_AGENT]);
		foreach ((array) json_decode((string) ($this->answer['result']['content'][0]['text'] ?? ''), true) as $connection) {
			if (($connection['id'] ?? null) === $key && ($connection['signed_in'] ?? null) === true) {
				return;
			}
		}
		throw new RuntimeException("list_connections does not offer $key signed in: " . substr((string) ($this->answer['result']['content'][0]['text'] ?? ''), 0, 300));
	}

	/** An answer with any other id, null included, matches no call: the client waits on the call
	 * until it gives up on the whole server. */
	#[Then('the answer carries the call\'s id')]
	public function answerCarriesId(): void
	{
		if (($this->answer['id'] ?? null) !== 3) {
			throw new RuntimeException('the answer is for id ' . json_encode($this->answer['id'] ?? null) . ', not the call\'s 3');
		}
	}

	/** Adminer signs out a login that failed, and the message says so: this keeps it honest. */
	#[Then('the reader\'s connection is listed, signed out')]
	public function readerSignedOut(): void
	{
		foreach ((array) json_decode((string) ($this->answer['result']['content'][0]['text'] ?? ''), true) as $connection) {
			if (($connection['id'] ?? null) === $this->readerKey()) {
				if (($connection['signed_in'] ?? null) !== false) {
					throw new RuntimeException('the reader is still signed in after its login failed');
				}
				return;
			}
		}
		throw new RuntimeException('list_connections no longer offers the reader: ' . substr((string) ($this->answer['result']['content'][0]['text'] ?? ''), 0, 300));
	}

	#[Then('the database named is :database')]
	public function databaseNamed(string $database): void
	{
		$said = (array) json_decode((string) ($this->answer['result']['content'][0]['text'] ?? ''), true);
		if (($said['database'] ?? null) !== $database) {
			throw new RuntimeException("current_connection names the database " . json_encode($said['database'] ?? null) . ", not $database");
		}
	}

	/** A call that went somewhere else must not have taken the window, or the next call without
	 * arguments, along with it. */
	#[Then('the handshake still points at the window\'s database')]
	public function handshakeStillOnWindow(): void
	{
		$url = (string) ($this->readHandshake()['url'] ?? '');
		if (!str_contains($url, 'db=' . E2E_DATABASE)) {
			throw new RuntimeException("a call on another database moved the window's handshake: $url");
		}
	}

	/** Straight at the gate, to read the status the bridge never shows: adminer answers a JSON
	 * body with 413, taking it for one over post_max_size. */
	#[Then('the endpoint answers 200, not adminer\'s 413')]
	public function endpointAnswers200(): void
	{
		$handshake = $this->readHandshake();
		$this->postToGate((string) $handshake['url'], (string) $handshake['token']);
		$status = (string) (http_get_last_response_headers()[0] ?? '');
		if (!str_contains($status, ' 200 ')) {
			throw new RuntimeException("the MCP endpoint answered $status");
		}
	}

	#[When('an agent runs the query :sql')]
	public function agentRunsQuery(string $sql, bool $whole = false): void
	{
		$arguments = ['sql' => $sql] + ($whole ? ['whole_values' => true] : []);
		$this->answer = $this->rpc([
			'id' => 4,
			'method' => 'tools/call',
			'params' => ['name' => 'execute_query', 'arguments' => $arguments],
		]);
		// The tool's payload is JSON inside a JSON string, so it is decoded twice — a naive
		// contains('"rolled_back"') looks for a quote that is escaped by the time it reaches here.
		$this->payload = (array) json_decode((string) ($this->answer['result']['content'][0]['text'] ?? ''), true);
	}

	#[When('an agent runs the query :sql asking for whole values')]
	public function agentRunsQueryWhole(string $sql): void
	{
		$this->agentRunsQuery($sql, true);
	}

	/** A cut value has to be named in the answer: a cut jsonb stops parsing, a cut text column
	 * fails nowhere at all. Counted in characters, so the cut cannot split a UTF-8 sequence.
	 */
	#[Then('the value in :column is cut to :kept characters of :length, and the answer says so')]
	public function valueCutAndReported(string $column, int $kept, int $length): void
	{
		$value = (string) ($this->payload['rows'][0][$column] ?? '');
		if (mb_strlen($value, 'UTF-8') !== $kept + mb_strlen('… [truncated]', 'UTF-8') || !mb_check_encoding($value, 'UTF-8')) {
			throw new RuntimeException("$column came back as " . mb_strlen($value, 'UTF-8') . ' characters: ' . mb_substr($value, -20));
		}
		if ($this->cut($column) !== ['row' => 0, 'column' => $column, 'length' => $length]) {
			throw new RuntimeException("the cut of $column is not reported: " . json_encode($this->payload['truncated_cells'] ?? null));
		}
		if (!str_contains((string) ($this->payload['truncated_note'] ?? ''), 'whole_values: true')) {
			throw new RuntimeException('the answer does not say how to get the whole value: ' . json_encode($this->payload['truncated_note'] ?? null));
		}
	}

	#[Then('the answer says :column was cut too')]
	public function answerSaysCut(string $column): void
	{
		if ($this->cut($column) === null) {
			throw new RuntimeException("the cut of $column is not reported: " . json_encode($this->payload['truncated_cells'] ?? null));
		}
	}

	#[Then('the value in :column comes back whole, :length characters')]
	public function valueWhole(string $column, int $length): void
	{
		$value = (string) ($this->payload['rows'][0][$column] ?? '');
		if (mb_strlen($value, 'UTF-8') !== $length || ($this->payload['truncated_cells'] ?? null) !== []) {
			throw new RuntimeException("$column came back as " . mb_strlen($value, 'UTF-8') . ' characters, cuts: ' . json_encode($this->payload['truncated_cells'] ?? null));
		}
	}

	/** Longer than the cap, or a value that was never at risk of being cut would pass. */
	#[Then('the value in :column comes back whole as JSON, over :length characters')]
	public function valueWholeJson(string $column, int $length): void
	{
		$value = (string) ($this->payload['rows'][0][$column] ?? '');
		if (mb_strlen($value, 'UTF-8') <= $length || $this->cut($column) !== null) {
			throw new RuntimeException("$column came back as " . mb_strlen($value, 'UTF-8') . ' characters, cuts: ' . json_encode($this->payload['truncated_cells'] ?? null));
		}
		json_decode($value, flags: JSON_THROW_ON_ERROR);
	}

	#[Then('the value in :column comes back as hex :hex, :bytes bytes long')]
	public function valueAsHex(string $column, string $hex, int $bytes): void
	{
		$value = $this->payload['rows'][0][$column] ?? null;
		$listed = $this->payload['binary_cells'] ?? null;
		if ($value !== $hex || $listed !== [['row' => 0, 'column' => $column, 'length' => $bytes]]) {
			throw new RuntimeException("$column came back as " . json_encode($value) . ', listed: ' . json_encode($listed) . ', answer: ' . json_encode($this->answer));
		}
	}

	/** @return array<string,mixed>|null the truncated_cells entry for this column */
	private function cut(string $column): ?array
	{
		foreach ((array) ($this->payload['truncated_cells'] ?? []) as $cell) {
			if (($cell['column'] ?? null) === $column) {
				return $cell;
			}
		}
		return null;
	}

	#[Then('the answer is an error mentioning :text')]
	public function answerIsError(string $text): void
	{
		$said = (string) ($this->answer['result']['content'][0]['text'] ?? '');
		if (($this->answer['result']['isError'] ?? null) !== true || !str_contains($said, $text)) {
			throw new RuntimeException("expected an error mentioning $text: " . substr($said, 0, 300));
		}
	}

	/** The database said no, not us: what least privilege is meant to look like from the agent. */
	#[Then('the database refused it')]
	public function databaseRefused(): void
	{
		$this->answerIsError('permission denied');
	}

	#[Then('the answer mentions :text')]
	public function answerMentions(string $text): void
	{
		$said = (string) ($this->answer['result']['content'][0]['text'] ?? '');
		if (!str_contains($said, $text)) {
			throw new RuntimeException("the answer does not mention $text: " . substr($said, 0, 200));
		}
	}

	/** A statement with no rows to return is an answer: as an error, a committed write read as a
	 * failed one, and an agent that retried it wrote twice. */
	#[Then('it is answered with :count affected row(s), not as an error')]
	public function answeredWithAffectedRows(int $count): void
	{
		if (($this->answer['result']['isError'] ?? null) === true || $this->payload === null) {
			throw new RuntimeException('a statement with no result set was answered as an error: ' . json_encode($this->answer));
		}
		if (($this->payload['affected_rows'] ?? null) !== $count) {
			throw new RuntimeException("expected $count affected row(s): " . json_encode($this->payload));
		}
	}

	/** With writes off the statement ran and was undone, and the agent has to be told which: a
	 * row count alone reads as a write that stayed. */
	#[Then('the answer says it was rolled back')]
	public function answerSaysRolledBack(): void
	{
		if (($this->payload['rolled_back'] ?? null) !== true || !str_contains((string) ($this->payload['note'] ?? ''), 'rolled back')) {
			throw new RuntimeException('the answer does not say the write was rolled back: ' . json_encode($this->payload));
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

	/** The bridge as an agent runs it: a bare child process, over its stdin and stdout. */
	#[When('an agent runs :sql through the stdio bridge')]
	public function throughTheStdioBridge(string $sql): void
	{
		$bridge = new Bridge($this->fix['root'], new Handshake((string) $this->fix['data']));
		$this->raw = $bridge->pump(implode("\n", [
			(string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => []]),
			(string) json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => [
				'name' => 'execute_query',
				'arguments' => ['sql' => $sql],
			]]),
		]) . "\n");
		$bridge->close();
		$this->payload = null;
		foreach (explode("\n", trim($this->raw)) as $line) {
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
		if ($this->goBridge() === null) {
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
			return;
		}

		// The Go bridge: prove it by using it. A handshake in the platform's own home, reached with
		// no ADMINER_DESKTOP_DATA — the stub answers, so nothing but that handshake could have.
		$home = $this->scratch();
		$dir = $this->platformHome($home);
		@mkdir($dir, 0700, true);
		file_put_contents($dir . '/mcp.json', (string) json_encode(['url' => $this->appStub('json'), 'token' => 'tok', 'socket' => $this->appSocket]));
		$this->bridge = new Bridge($this->fix['root'], new Handshake($dir), [
			'ADMINER_DESKTOP_DATA' => null,
			'HOME' => $home,
			'XDG_CONFIG_HOME' => null,
		]);
		$this->raw = $this->bridge->exchange($this->request());
		if ($this->raw !== '{"jsonrpc":"2.0","id":7,"result":{"tools":[]}}') {
			throw new RuntimeException('with no ADMINER_DESKTOP_DATA the bridge did not find the handshake: ' . (string) $this->raw);
		}
	}

	#[Given('the app is not running')]
	public function appIsNotRunning(): void
	{
		$this->handshake = new Handshake($this->scratch());
		$this->handshake->clear();
		$this->bridge = $this->bridgeUnderTest();
	}

	/** The bridge reads the settings beside the handshake, as it does from the app's data dir. */
	#[Given('the app is not running, with writes allowed in its settings')]
	public function appIsNotRunningWithWrites(): void
	{
		$this->appIsNotRunning();
		file_put_contents(dirname((string) $this->handshake?->path()) . '/settings.json', (string) json_encode(['mcp' => true, 'mcp_write' => true]));
	}

	#[Given('the window was closed')]
	public function windowWasClosed(): void
	{
		// As a Cmd+Q leaves it: the socket file still there, and nothing listening on it.
		$this->handshake = new Handshake($this->scratch());
		$url = $this->appStub('json');
		$this->app?->stop();
		$this->app = null;
		$this->handshake->write($url, 'tok', '', $this->appSocket);
		$this->bridge = $this->bridgeUnderTest();
	}

	/** A real HTTP answer, because a canned string in-process proves nothing about what the bridge
	 * does with a login page or an empty body once the transport has been through it. */
	#[Given('the app answers with :what')]
	public function appAnswersWith(string $what): void
	{
		$modes = [
			'nothing' => 'nothing',
			'a login page' => 'login',
			'a json answer' => 'json',
			'a json answer behind a BOM' => 'padded',
			'a json error page' => 'error',
		];
		if (!isset($modes[$what])) {
			throw new RuntimeException("no such answer to stub: $what");
		}
		$this->handshake = new Handshake($this->scratch());
		$this->handshake->write($this->appStub($modes[$what]), 'tok', '', $this->appSocket);
		$this->bridge = $this->bridgeUnderTest();
	}

	/** What an older version wrote: a port and no socket. The port may be someone else's by now,
	 * so nothing is sent there, and the stub on it would record it if it were. */
	#[Given('the handshake is one an older version wrote, with no socket')]
	public function handshakeWithoutSocket(): void
	{
		$this->handshake = new Handshake($this->scratch());
		$this->handshake->write($this->appStub('json'), 'tok');
		$this->bridge = $this->bridgeUnderTest();
	}

	#[Then('nothing reached the app')]
	public function nothingReachedTheApp(): void
	{
		if (is_file($this->appRecord) && trim((string) file_get_contents($this->appRecord)) !== '') {
			throw new RuntimeException('the app was posted to: ' . file_get_contents($this->appRecord));
		}
	}

	/** A handshake that names somewhere other than this machine: whoever is there would get the
	 * token and the SQL, and answer as us. */
	#[Given('the handshake points off this machine')]
	public function handshakeOffMachine(): void
	{
		$this->handshake = new Handshake($this->scratch());
		$this->handshake->write('http://example.com/adminer.php?pgsql=x&db=demo&', 'tok');
		$this->bridge = $this->bridgeUnderTest();
	}

	#[When('a client initializes through the bridge')]
	public function initializeThroughBridge(): void
	{
		$this->answer = (array) json_decode(
			(string) $this->bridge?->exchange((string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize'])),
			true,
		);
	}

	#[When('a client at :version initializes through the bridge')]
	public function initializeAtVersionThroughBridge(string $version): void
	{
		$this->answer = (array) json_decode((string) $this->bridge?->exchange((string) json_encode(
			['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => $version]],
		)), true);
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

	/** Pumped, not exchanged: the bridge exits at the end of its stdin, so silence is everything
	 * it wrote by then, rather than whatever a timeout happened not to see. */
	#[When('a notification arrives through the bridge')]
	public function notificationThroughBridge(): void
	{
		$said = $this->bridge?->pump((string) json_encode(
			['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
		) . "\n");
		$this->raw = $said === null || $said === '' ? null : $said;
	}

	#[When('a client probes for a newer protocol through the bridge')]
	public function discoverThroughBridge(): void
	{
		$this->answer = (array) json_decode((string) $this->bridge?->exchange((string) json_encode(
			['jsonrpc' => '2.0', 'id' => 9, 'method' => 'server/discover'],
		)), true);
	}

	/** What the app answers once it is up: a dual-era client reads -32601 as a legacy server and
	 * falls back to initialize. A result would read as a discover that succeeded. */
	#[Then('it is told the method is unknown, as the app would say')]
	public function toldMethodUnknown(): void
	{
		if (($this->answer['error']['code'] ?? null) !== -32601 || isset($this->answer['result'])) {
			throw new RuntimeException('server/discover was not answered -32601: ' . json_encode($this->answer));
		}
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

	/** Without this, a model that met an unavailable app concluded the server was not connected
	 * and wrote its own bridge to the database. Instructions reach its system prompt before any call.
	 */
	#[Then('the agent is told not to reach the database another way')]
	public function toldNotToWorkAround(): void
	{
		$instructions = (string) ($this->answer['result']['instructions'] ?? '');
		if (!str_contains($instructions, 'write a bridge of your own')) {
			throw new RuntimeException("initialize does not say to stay on these tools: $instructions");
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
		$this->handshake->write($this->appStub('json'), 'tok', '', $this->appSocket);
		$this->bridge = $this->bridgeUnderTest();
		$this->bridge->exchange($this->request());
		$recorded = $this->recorded();
		$this->posted = [
			'url' => $recorded['uri'],
			'body' => $recorded['body'],
			'token' => (string) preg_replace('~^Bearer ~', '', $recorded['authorization']),
		];
	}

	#[Then('it posts to the connected url with mcp=1, showing the gate its token')]
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
		if (($posted['token'] ?? null) !== 'tok') {
			throw new RuntimeException('the bridge did not show the gate its token');
		}
	}

	/** run() pumps a stream: blank lines skipped, one answer per request, silence stays silent. A
	 * stub that answered every message alike would prove nothing about the silence, so this one
	 * answers like the app — a notification with an empty body.
	 */
	#[Then('the bridge answers each request once and every notification never')]
	public function bridgePumpsTheStream(): void
	{
		$this->handshake = new Handshake($this->scratch());
		$this->handshake->write($this->appStub('app'), 'tok', '', $this->appSocket);
		$this->bridge = $this->bridgeUnderTest();
		$written = $this->bridge->pump($this->request() . "\n\n"
			. (string) json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']) . "\n"
			. $this->request() . "\n");
		if (substr_count($written, '"result"') !== 2 || substr_count($written, "\n") !== 2) {
			throw new RuntimeException("the stream was answered as: $written");
		}
	}

	/** A socket stdin, as Claude Code spawns its servers with, quiet for longer than its read
	 * timeout: Bridge::pumpQuiet() cuts PHP's to 1 s so the scenario stays fast. */
	#[Then('the bridge outlives a quiet socket and answers what comes after')]
	public function bridgeOutlivesQuietSocket(): void
	{
		$this->handshake = new Handshake($this->scratch());
		$this->handshake->write($this->appStub('json'), 'tok', '', $this->appSocket);
		$this->bridge = $this->bridgeUnderTest();
		$written = $this->bridge->pumpQuiet($this->request());
		if (substr_count($written, '"result"') !== 2) {
			throw new RuntimeException("the bridge stopped at the first quiet spell, having answered: $written");
		}
	}

	/** The app under test, answering one canned body per POST and recording what it was sent. */
	private function appStub(string $mode): string
	{
		$dir = $this->scratch();
		$this->appRecord = $dir . '/requests.jsonl';
		// Its own 0700 directory, as the gate's is, and a short path: a unix socket's has to stay
		// under about a hundred bytes, which the scratch directory's already nearly is.
		$sockets = sys_get_temp_dir() . '/ms' . bin2hex(random_bytes(4));
		mkdir($sockets, 0700);
		$this->appSocket = $sockets . '/call.sock';
		register_shutdown_function(static function () use ($sockets): void {
			@unlink($sockets . '/call.sock');
			@rmdir($sockets);
		});
		$process = new Process([
			$this->fix['root'] . '/bin/frankenphp',
			'php-cli',
			dirname(__DIR__) . '/harness/mcp-app.php',
			$this->appSocket,
			$mode,
			$this->appRecord,
		]);
		$process->start();
		$process->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'listening'));
		$this->app = $process;
		// A name for the Host header only: the bridge posts to the socket.
		return 'http://127.0.0.1:' . e2e_free_port(56000) . '/adminer.php?pgsql=x&db=demo&';
	}

	/** @return array{uri:string, authorization:string, body:string} the last request the stub got */
	private function recorded(): array
	{
		$lines = file($this->appRecord, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
		$last = end($lines);
		$decoded = $last === false ? null : json_decode($last, true);
		if (!is_array($decoded)) {
			throw new RuntimeException('the app was not asked anything');
		}
		return [
			'uri' => (string) ($decoded['uri'] ?? ''),
			'authorization' => (string) ($decoded['authorization'] ?? ''),
			'body' => (string) ($decoded['body'] ?? ''),
		];
	}

	private function bridgeUnderTest(): Bridge
	{
		$handshake = $this->handshake;
		if ($handshake === null) {
			throw new RuntimeException('no handshake to give the bridge');
		}
		return new Bridge($this->fix['root'], $handshake);
	}

	private function goBridge(): ?string
	{
		$binary = getenv('ADMINER_DESKTOP_MCP_BRIDGE');
		return $binary === false || $binary === '' ? null : $binary;
	}

	/** Where os.UserConfigDir() points with this HOME, which differs on macOS. */
	private function platformHome(string $home): string
	{
		return PHP_OS_FAMILY === 'Darwin'
			? $home . '/Library/Application Support/Adminer Desktop'
			: $home . '/.config/Adminer Desktop';
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
	 * leave the agent parsing a login page. A JSON-RPC error would fail initialize, which a client
	 * shows as a dead server: so the handshake and the tool list answer as ever, and a call says
	 * what to do.
	 */
	#[Then('a request on a stale handshake is told to log in again')]
	public function staleHandshakeIsAnswered(): void
	{
		$endpoint = new \Desktop\Mcp\Endpoint(new UserSettings(null));
		$initialize = (array) json_decode((string) $endpoint->answer('{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}', false), true);
		if (!isset($initialize['result']['protocolVersion'])) {
			throw new RuntimeException('initialize failed on a stale handshake: ' . json_encode($initialize));
		}
		$list = (array) json_decode((string) $endpoint->answer('{"jsonrpc":"2.0","id":2,"method":"tools/list"}', false), true);
		if (($list['result']['tools'] ?? []) === []) {
			throw new RuntimeException('a stale handshake offered no tools: ' . json_encode($list));
		}
		$call = (array) json_decode((string) $endpoint->answer('{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"list_tables"}}', false), true);
		if (($call['result']['isError'] ?? null) !== true || !str_contains((string) ($call['result']['content'][0]['text'] ?? ''), 'Log in again')) {
			throw new RuntimeException('a call on a stale handshake does not say to log in again: ' . json_encode($call));
		}
		if ($endpoint->answer('{"jsonrpc":"2.0","method":"tools/call","params":{"name":"list_tables"}}', false) !== null) {
			throw new RuntimeException('a notification on a stale handshake was answered');
		}
	}

	/** The parse behind the MySQL refusal, with no MySQL: the e2e MCP scenarios run on postgres. */
	#[Then('of MySQL\'s grants only those beyond one database\'s data are refused')]
	public function mysqlGrantsJudged(): void
	{
		$cases = [
			'GRANT ALL PRIVILEGES ON *.* TO `root`@`localhost` WITH GRANT OPTION' => ['ALL PRIVILEGES'],
			'GRANT SELECT, FILE, SUPER ON *.* TO `u`@`%`' => ['SELECT', 'FILE', 'SUPER'],
			'GRANT BACKUP_ADMIN,BINLOG_ADMIN ON *.* TO `root`@`localhost`' => ['BACKUP_ADMIN', 'BINLOG_ADMIN'],
			'GRANT SELECT ON *.* TO `u`@`%`' => ['SELECT'],
			'GRANT SELECT ON `mysql`.`user` TO `u`@`%`' => ['SELECT ON `mysql`.`user`'],
			'GRANT SELECT ON MYSQL.* TO `u`@`%`' => ['SELECT ON MYSQL.*'],
			'GRANT SELECT ON `my%`.* TO `u`@`%`' => ['SELECT ON `my%`.*'],
			'GRANT SELECT ON `m_sql`.* TO `u`@`%`' => ['SELECT ON `m_sql`.*'],
			'GRANT SELECT ON `m\_sql`.* TO `u`@`%`' => [],
			"GRANT PROXY ON ''@'' TO 'root'@'localhost' WITH GRANT OPTION" => ["PROXY ON ''@''"],
			'GRANT SELECT ON `broken TO `u`@`%`' => ['GRANT SELECT ON `broken TO `u`@`%`'],
			'GRANT USAGE ON *.* TO `agent`@`%`' => [],
			"GRANT USAGE ON *.* TO `agent`@`%` IDENTIFIED BY PASSWORD '*0123ABCD'" => [],
			'GRANT ALL PRIVILEGES ON `app`.* TO `agent`@`%`' => [],
			'GRANT SELECT ON `my app`.* TO `agent`@`%`' => [],
			'GRANT SELECT (`id`, `name`) ON `app`.`users` TO `agent`@`%`' => [],
			'GRANT `dba`@`%` TO `agent`@`%`' => [],
		];
		foreach ($cases as $grant => $expected) {
			$found = Tools::overprivilegedGrants([$grant]);
			if ($found !== $expected) {
				throw new RuntimeException("$grant: expected " . json_encode($expected) . ', got ' . json_encode($found));
			}
		}
	}

	// ── the plumbing ─────────────────────────────────────────────────────────────────────────

	/** Log in with curl on the demo server, keeping the cookies — the same borrow the bridge
	 * performs. Every login of the fixture shares the driver's password. */
	private function logIn(string $username, string $database): void
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
				'username' => $username,
				'password' => (string) $driver['password'],
				'db' => $database,
			],
			'token' => $token[1] ?? '',
		]), follow: true);
		if (!str_contains($after, 'Logout') && !str_contains($after, 'logout=1')) {
			throw new RuntimeException("could not log in as $username with curl, the rest of the scenario would be meaningless");
		}
	}


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

	/** One JSON-RPC message the way an agent sends it: through the bridge and the gate, with the
	 * token and none of the session's cookies, which only the gate holds.
	 * @param array<string, mixed> $message
	 * @return array<string, mixed>
	 */
	private function rpc(array $message): array
	{
		$bridge = new Bridge($this->fix['root'], new Handshake((string) $this->fix['data']));
		try {
			return (array) json_decode((string) $bridge->exchange((string) json_encode(['jsonrpc' => '2.0'] + $message)), true);
		} finally {
			$bridge->close();
		}
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
			$remove = static function (string $path) use (&$remove): void {
				if (is_dir($path) && !is_link($path)) {
					array_map($remove, (array) glob("$path/*"));
					@rmdir($path);
					return;
				}
				@unlink($path);
			};
			$remove($dir);
		});
		return $dir;
	}
}
