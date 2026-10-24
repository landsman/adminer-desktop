<?php
declare(strict_types=1);

namespace Desktop\Mcp;

use Desktop\Connections\Connection;
use Desktop\Connections\Connections;
use Desktop\Connections\Environment;
use Desktop\Env;
use Desktop\I18n\Strings;
use Desktop\SettingKey;
use Desktop\UserSettings;

/** The MCP endpoint as adminer sees it: what AdminerDesktop::headers() delegates to.
*
* It has to be a hook rather than an action in api.php, and that is not a preference. api.php
* boots the autoloader and nothing else, so a handler there has no connection and no Adminer\
* functions; and adminer.php cannot be included to get one, because it terminates the request —
* control never returns to the line after the include. A hook is the seam adminer provides for
* running inside a request it has already authenticated and connected, which is exactly what
* this needs.
*
* Two jobs, both only ever on such a request: on a page the window loads, refresh the handshake so
* the agent can still find this window — and tell the launcher's gate which session to lend it —
* and on ?mcp=1, answer JSON-RPC instead of HTML.
*
* A call may name another saved connection, or another database, than the window's. The gate only
* ever sends calls to the window's endpoint (launcher/mcpgate), so the call lands here and is passed
* on from here: a second request to this same server, with the window's session, at the URL of the
* connection asked for. Adminer connects per request from the URL, and a request knows only its own
* driver, so there is no reaching another connection from inside this one.
*
* serve() does the I/O and exits; url() and answer() are the decisions behind it, kept separate
* so they can be checked without a request to make or a process to end.
*/
class Endpoint {
	private UserSettings $settings;
	private Handshake $handshake;
	private Server $server;
	private Activity $activity;
	private RequestLog $log;
	private ?Connections $connections;

	/** The ?mcp= value of a call passed on from another request, which has logged it already.
	* Anyone may set it, and that is fine: the gate sends an agent only to ?mcp=1, and a caller
	* reaching this server directly with the window's cookies holds the whole session anyway. */
	private const string FORWARDED = 'forwarded';

	/** The JSON-RPC code of an answer from a request adminer could not connect. */
	private const int NOT_CONNECTED = -32000;

	function __construct(UserSettings $settings, ?Handshake $handshake = null, ?Server $server = null, ?Activity $activity = null, ?RequestLog $log = null, ?Connections $connections = null) {
		$this->settings = $settings;
		$this->handshake = $handshake ?? new Handshake();
		$this->connections = $connections;
		// Never on a connection marked Prod, whatever the setting says: the agent picks its own
		// connection now, and one switch should not reach every database the user is signed in to.
		$allowed = (bool) $settings->get(SettingKey::McpWrite, false);
		$write = $allowed && $connections?->current()?->environment !== Environment::Prod;
		$whole = (bool) $settings->get(SettingKey::McpWholeValues, false);
		// The description takes the setting itself, not $write: it covers every connection the agent
		// may name, and states the Prod exception in words.
		$this->server = $server ?? new Server(new Tools($write, $whole, $connections), $allowed, $whole);
		$this->activity = $activity ?? new Activity();
		$this->log = $log ?? new RequestLog();
	}

	/** The hook body. Returns null so the hook has no opinion for other plugins; the MCP path
	* never returns at all.
	*/
	function serve(): ?string {
		if (!$this->settings->get(SettingKey::Mcp, false)) {
			// Off, or turned off since the last run: a handshake left on disk, or a session left
			// with the gate, would still let an agent in, so retract both rather than ignore them.
			$this->handshake->clear();
			$this->report('', '');
			return null;
		}
		$connected = \Adminer\driver() !== null;
		if (!isset($_GET["mcp"])) {
			// Only a page the window loads says where the window is. A call is answered wherever it
			// asked to be, and reporting that would move every later call, and the panel, with it.
			// The gate's address, not ours: the bridge reaches this window through the launcher,
			// which adds the session the agent never sees. Without a launcher there is no gate,
			// nothing to hold the session, and so no handshake at all.
			$gate = Env::McpGate->get();
			$token = Env::McpToken->get();
			$url = $gate !== false && $gate !== '' ? $this->url(['HTTP_HOST' => $gate] + $_SERVER, $connected, \Adminer\ME) : null;
			if ($url !== null && $token !== false && $token !== '') {
				$this->report($this->cookie(), self::at($url));
				$this->handshake->write($url, $token, $this->target(), (string) Env::McpSocket->get());
			}
			return null;
		}
		header("Content-Type: application/json");
		$input = (string) file_get_contents("php://input");
		// Before answering, so a call that goes on to fail still shows up: "something asked me
		// this and it broke" is exactly as worth seeing as a call that worked.
		$now = time();
		if ($_GET["mcp"] !== self::FORWARDED) {
			[$method, $tool, $detail] = $this->describe($input);
			$this->activity->record($method, $now);
			$this->log->append($method, $tool, $detail, $now);
		}
		$answer = $this->answer($input, $connected);
		// header(), not http_response_code(): a POST with no form fields — a JSON body — is what
		// adminer takes for one over post_max_size, so it has already sent header("HTTP/1.1 413"),
		// and PHP ignores http_response_code() after that. A notification is answered with silence.
		header($answer === null ? "HTTP/1.1 204 No Content" : "HTTP/1.1 200 OK");
		echo $answer ?? '';
		exit;
	}

	/** Where the MCP endpoint is for a connection's URL: the bridge posts there, and the gate sends
	* every call there whatever path it came in on.
	*/
	static function at(string $url): string {
		// adminer's ME ends in "&", which a second one would leave an empty pair after.
		return str_contains($url, '?') ? rtrim($url, '&') . '&mcp=1' : "$url?mcp=1";
	}

	/** Tell the gate which session an agent's call may borrow, and the one endpoint it may borrow
	* it for, over its unix socket: only the launcher reads it, and nothing is ever sent back
	* (launcher/mcpgate). One connection per report, so two requests reporting at once cannot
	* interleave.
	*/
	private function report(string $cookie, string $endpoint): void {
		$socket = Env::McpReport->get();
		if ($socket === false || $socket === '') {
			return;
		}
		$gate = @stream_socket_client("unix://$socket", $errno, $error, 1);
		if ($gate !== false) {
			@fwrite($gate, json_encode(['cookie' => $cookie, 'endpoint' => $endpoint]) . "\n");
			fclose($gate);
		}
	}

	/** The two cookies that unlock this session: its id, and adminer_key, which the passwords in
	* it are encrypted with. Nothing else is needed to borrow it, adminer_permanent included.
	*/
	private function cookie(): string {
		$pairs = [];
		foreach ([(string) session_name(), 'adminer_key'] as $name) {
			if (isset($_COOKIE[$name]) && is_string($_COOKIE[$name])) {
				$pairs[] = rawurlencode($name) . '=' . rawurlencode($_COOKIE[$name]);
			}
		}
		return implode('; ', $pairs);
	}

	/** The absolute URL to record, or null when there is nothing worth recording.
	*
	* Adminer\ME rather than the script name, and that is load-bearing: adminer takes the server
	* and database from the query string rather than the session, so a bare adminer.php reaches
	* a driverless adminer that can answer nothing. ME is the prefix adminer builds its own
	* links from — this script with the connection already in it.
	*
	* Not connected means not logged in, so the handshake exists only while there is a session
	* worth borrowing.
	*
	* @param array<string,mixed> $server $_SERVER, passed in so this can be checked directly
	* @param string $me Adminer\ME, likewise: a constant only defined once adminer is loaded,
	*                   so taking it as an argument is what lets this be checked without it
	*/
	function url(array $server, bool $connected, string $me): ?string {
		$host = (string) ($server["HTTP_HOST"] ?? "");
		if (!$connected || $host === "") {
			return null;
		}
		// Normalise the separators *before* dirname(), not after. On a POSIX host dirname() does
		// not treat a backslash as a separator, so a Windows SCRIPT_NAME like \tools\adminer.php
		// is one long filename to it and it answers "." — which then builds http://host./…
		// rather than a path. Doing it in this order is the whole of the fix.
		$script = str_replace("\\", "/", (string) ($server["SCRIPT_NAME"] ?? "/"));
		$dir = rtrim(dirname($script), "/");
		return "http://$host$dir/" . $me;
	}

	/** The connection an agent reaching us would land on, in the form a person recognises.
	*
	* Read here rather than in the panel because only a connected request knows it: the panel is
	* drawn on the login page too, where these constants say nothing.
	*/
	private function target(): string {
		$server = \Adminer\SERVER !== '' ? \Adminer\SERVER : 'localhost';
		return \Adminer\DRIVER . ' ' . $server . ($this->database() !== '' ? ' / ' . $this->database() : '');
	}

	private function database(): string {
		return \Adminer\DB;
	}

	/** The JSON-RPC method a request is asking for, for the record of what an agent did.
	*
	* Its own tiny parse rather than reaching into Server: this runs whether or not we are
	* connected, and a malformed body still counts as an agent having called.
	*/
	/** @return array{0:string,1:string,2:string} method, tool, and the argument worth logging */
	private function describe(string $input): array {
		$message = json_decode($input, true);
		if (!is_array($message)) {
			// Still a call, and a malformed one is worth seeing in the log rather than dropping.
			return ['unknown', '', ''];
		}
		$method = isset($message['method']) && is_string($message['method']) ? $message['method'] : 'unknown';
		$params = is_array($message['params'] ?? null) ? $message['params'] : [];
		$tool = isset($params['name']) && is_string($params['name']) ? $params['name'] : '';
		$args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
		// The SQL for a query, the table for everything else: what was asked, never what came
		// back. Results are the database's contents, and a plaintext copy of those beside it
		// would be a worse leak than the access being recorded.
		$detail = '';
		foreach (['sql', 'table'] as $key) {
			if (isset($args[$key]) && is_string($args[$key])) {
				$detail = $args[$key];
				break;
			}
		}
		// And where, when the call named somewhere other than the window.
		$where = trim(implode(' ', array_filter([$args['connection'] ?? '', $args['database'] ?? ''], 'is_string')));
		return [$method, $tool, $where !== '' ? "[$where] $detail" : $detail];
	}

	/** The body to answer an MCP request with, or null for 204.
	*
	* @param string $input the raw JSON-RPC request
	*/
	function answer(string $input, bool $connected): ?string {
		$request = json_decode($input, true);
		$id = is_array($request) ? ($request['id'] ?? null) : null;
		if (!$connected) {
			// Cookies good enough to reach us, but no connection: the window was signed out, or a
			// handshake outlived the login it was written for. Falling through would crash on a
			// null driver.
			if (($_GET["mcp"] ?? '') === self::FORWARDED) {
				// The request that passed a call on reads this code as "the saved login did not open"
				// (forward()); it is between our two requests, and never reaches an agent.
				return (string) json_encode(["jsonrpc" => "2.0", "id" => $id, "error" => [
					"code" => self::NOT_CONNECTED,
					"message" => Strings::t('mcp.agent_not_connected'),
				]]);
			}
			// To an agent, as the bridge answers a closed app (Stdio::unavailable()): initialize,
			// tools/list and ping need no database, so they answer as ever, and a tool call is a
			// result it can read out. A JSON-RPC error here failed initialize, which a client shows
			// as a dead server with the sentence saying what to do dropped, and left a model with no
			// tools to conclude it should find its own way to the database.
			$method = is_array($request) && is_string($request['method'] ?? null) ? $request['method'] : '';
			if ($method !== 'tools/call') {
				return $this->server->dispatch($input);
			}
			if ($id === null) {
				return null; // a notification, whatever its method
			}
			return (string) json_encode(['jsonrpc' => '2.0', 'id' => $id, 'result' => [
				'content' => [['type' => 'text', 'text' => Strings::t('mcp.agent_not_connected')]],
				'isError' => true,
			]]);
		}
		try {
			// A database adminer could not open still renders a page, and this hook with it, on
			// whatever it fell back to: postgres connects to `postgres` instead. Answering there
			// would hand the agent another database's tables under the name it asked for. Postgres
			// only: there select_db() just says how the connect went, elsewhere it runs USE or
			// ATTACH again.
			if (\Adminer\DRIVER === 'pgsql' && \Adminer\DB !== '' && !\Adminer\connection()->select_db(\Adminer\DB)) {
				$error = (string) \Adminer\connection()->error;
				throw new McpError(Strings::t('mcp.agent_no_database') . ': ' . \Adminer\DB . ($error !== '' ? " ($error)" : ''));
			}
			$elsewhere = is_array($request) ? $this->elsewhere($request) : null;
			if ($elsewhere !== null) {
				return $this->forward(...$elsewhere);
			}
		} catch (McpError $e) {
			return (string) json_encode(['jsonrpc' => '2.0', 'id' => $id, 'result' => [
				'content' => [['type' => 'text', 'text' => $e->getMessage()]],
				'isError' => true,
			]]);
		}
		return $this->server->dispatch($input);
	}

	/** Where a tool call that named another connection or database has to run, and the message
	* to send there, or null when it runs here.
	*
	* Only a saved connection the session holds a password for: anything else would be adminer's
	* login page, and an id that was never saved is not ours to guess a URL for. The database is
	* any name — adminer opens it with the same login, or the check in answer() says it could not.
	*
	* @param array<mixed> $request
	* @return array{string,string,string}|null url, body, the connection's id
	* @throws McpError
	*/
	private function elsewhere(array $request): ?array {
		$args = $request['params']['arguments'] ?? null;
		if (($request['method'] ?? '') !== 'tools/call' || !is_array($args)) {
			return null;
		}
		$here = $this->connections?->current()?->key() ?? '';
		$key = is_string($args['connection'] ?? null) && $args['connection'] !== '' ? $args['connection'] : $here;
		$database = is_string($args['database'] ?? null) && $args['database'] !== '' ? $args['database'] : ($key === $here ? \Adminer\DB : '');
		if ($key === $here && $database === \Adminer\DB) {
			return null;
		}
		$found = array_values(array_filter($this->connections?->saved() ?? [], fn(Connection $c) => $c->key() === $key && $c->signedIn));
		if ($found === []) {
			throw new McpError(Strings::t('mcp.agent_unknown_connection') . ": $key");
		}
		$connection = $found[0];
		// Built here rather than with adminer's auth_url(), which keeps the rest of this request's
		// query — the window's schema among it. ns= empty when a database is named: without it
		// postgres and MS SQL redirect to their default schema, and a redirect is not an answer.
		$query = [$connection->driver => $connection->server, 'username' => $connection->username]
			+ ($database !== '' ? ['db' => $database, 'ns' => ''] : [])
			+ ['mcp' => self::FORWARDED];
		unset($args['connection'], $args['database']);
		$request['params']['arguments'] = $args === [] ? new \stdClass() : $args;
		$script = str_replace("\\", "/", (string) ($_SERVER["SCRIPT_NAME"] ?? "/adminer.php"));
		return ['http://' . $_SERVER["HTTP_HOST"] . $script . '?' . http_build_query($query), (string) json_encode($request), $key];
	}

	/** Send the call on to the request that can answer it, with this window's session.
	*
	* Straight to this server rather than back through the gate: the gate opens one endpoint, the
	* window's, and the session is right here in this request's cookies. Adminer released the
	* session's lock before any hook ran (auth.inc.php, stop_session()), so the second request does
	* not wait on this one. It does need a free PHP thread, so the timeout stays under the bridge's
	* 60 s (Stdio::post(), and callTimeout in launcher/mcpbridge): a call stuck behind busy threads reaches the agent as this error.
	*
	* @throws McpError
	*/
	private function forward(string $url, string $body, string $key): string {
		$context = stream_context_create(['http' => [
			'method' => 'POST',
			'header' => ['Content-Type: application/json', 'Cookie: ' . $this->cookie()],
			'content' => $body,
			'timeout' => 50,
			'follow_location' => 0,
			'ignore_errors' => true,
		]]);
		$answer = @file_get_contents($url, false, $context);
		$status = (string) (http_get_last_response_headers()[0] ?? '');
		// No answer, or PHP failing: the connection is fine, so do not send the user to sign in.
		if (!is_string($answer) || preg_match('~^HTTP/\S+ 5~', $status)) {
			throw new McpError(Strings::t('mcp.agent_forward_failed') . ": $key" . ($status !== '' ? " ($status)" : ''));
		}
		if ($answer !== '' && $answer[0] !== '{') {
			// Adminer's login page: the password the list said it held did not open it.
			throw new McpError(Strings::t('mcp.agent_unknown_connection') . ": $key");
		}
		// The saved login did not open: its server is down, or the password changed. Adminer has
		// signed it out by now (auth_error()), as it does for a login typed into the window.
		$decoded = json_decode($answer, true);
		if (is_array($decoded) && ($decoded['error']['code'] ?? null) === self::NOT_CONNECTED) {
			throw new McpError(Strings::t('mcp.agent_connect_failed') . ": $key");
		}
		return $answer;
	}
}
