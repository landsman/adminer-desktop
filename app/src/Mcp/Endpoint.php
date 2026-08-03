<?php
declare(strict_types=1);

namespace Desktop\Mcp;

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
* Two jobs, both only ever on such a request: refresh the handshake so the agent can still find
* this window, and answer ?mcp=1 as JSON-RPC instead of HTML.
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

	function __construct(UserSettings $settings, ?Handshake $handshake = null, ?Server $server = null, ?Activity $activity = null, ?RequestLog $log = null) {
		$this->settings = $settings;
		$this->handshake = $handshake ?? new Handshake();
		$this->server = $server ?? new Server();
		$this->activity = $activity ?? new Activity();
		$this->log = $log ?? new RequestLog();
	}

	/** The hook body. Returns null so the hook has no opinion for other plugins; the MCP path
	* never returns at all.
	*/
	function serve(): ?string {
		if (!$this->settings->get(SettingKey::Mcp, false)) {
			// Off, or turned off since the last run: a handshake left on disk would still name
			// a live session, so retract it rather than merely ignoring it.
			$this->handshake->clear();
			return null;
		}
		$connected = \Adminer\driver() !== null;
		$url = $this->url($_SERVER, $connected, \Adminer\ME);
		if ($url !== null) {
			/** @var array<string,string> $cookies */
			$cookies = array_filter($_COOKIE, 'is_string');
			$this->handshake->write($url, $cookies, $this->target());
		}
		if (!isset($_GET["mcp"])) {
			return null;
		}
		header("Content-Type: application/json");
		$input = (string) file_get_contents("php://input");
		// Before answering, so a call that goes on to fail still shows up: "something asked me
		// this and it broke" is exactly as worth seeing as a call that worked.
		$now = time();
		[$method, $tool, $detail] = $this->describe($input);
		$this->activity->record($method, $now);
		$this->log->append($method, $tool, $detail, $now);
		$answer = $this->answer($input, $connected);
		if ($answer === null) {
			// A JSON-RPC notification is answered with silence, not an empty body with a 200.
			http_response_code(204);
		} else {
			echo $answer;
		}
		exit;
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
		return [$method, $tool, $detail];
	}

	/** The body to answer an MCP request with, or null for 204.
	*
	* @param string $input the raw JSON-RPC request
	*/
	function answer(string $input, bool $connected): ?string {
		if (!$connected) {
			// Cookies good enough to reach us, but no connection — a handshake outliving the
			// login it was written for. Say so in JSON: the agent is not reading HTML, and
			// falling through would crash on a null driver instead.
			return (string) json_encode(["jsonrpc" => "2.0", "id" => null, "error" => [
				"code" => -32000,
				"message" => "Adminer Desktop is not connected to a database. Log in again in the app.",
			]]);
		}
		return $this->server->dispatch($input);
	}
}
