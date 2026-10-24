<?php
declare(strict_types=1);

namespace Desktop\Mcp;

use Desktop\I18n\Strings;
use Desktop\SettingKey;
use Desktop\UserSettings;

/** The bridge an agent talks to: newline-delimited JSON-RPC on stdio, the running window on HTTP.
*
* MCP's stdio transport expects to *start* its server as a child process. This app is already
* running, on a port the launcher picks fresh every start, so nothing an agent spawns could know
* where to reach it. This class is the piece in between: per message it reads the handshake for
* the gate's current URL and token, forwards the message there, and writes the answer back. It
* never holds the window's session; the gate adds that (launcher/mcpgate).
*
* Per message, not once at startup, because the agent may have spawned us before the app was
* running and will certainly outlive a restart of it.
*
* The installed app runs the Go copy of this (launcher/mcpbridge); this one is the baseline the
* e2e suite holds it to, so the two answer alike.
*/
class Stdio {
	/** What post() returns when the window went quiet for the whole timeout, as opposed to not
	* answering at all: a write may have run, and the agent has to be told before it retries. */
	private const string TIMED_OUT = 'timed out';

	private Handshake $handshake;

	function __construct(?Handshake $handshake = null) {
		$this->handshake = $handshake ?? new Handshake();
	}

	/** Pump messages until stdin closes.
	*
	* Claude Code hands its servers a socketpair, which PHP opens as socket streams with
	* default_socket_timeout (60 s) on them: fgets then failed after a quiet minute and dropped the
	* server, and an fwrite the client is slow to read would stop halfway through a line. -1 blocks
	* until data or EOF; a pipe has no timeout and ignores it.
	*
	* @param resource $in
	* @param resource $out
	*/
	function run($in, $out): void {
		stream_set_timeout($in, -1);
		stream_set_timeout($out, -1);
		while (($line = fgets($in)) !== false) {
			$line = trim($line);
			if ($line === '') {
				continue;
			}
			$answer = $this->exchange($line);
			if ($answer !== null) {
				fwrite($out, $answer . "\n");
			}
		}
	}

	/** One message in, one message out — or null when there is nothing to say.
	*
	* Null covers two cases that must not be confused: a JSON-RPC notification, which the
	* protocol answers with silence, and a failed request that carried no id, which is also a
	* notification and so also gets none.
	*/
	function exchange(string $line): ?string {
		$message = json_decode($line, true);
		$id = is_array($message) ? ($message['id'] ?? null) : null;
		$method = is_array($message) && is_string($message['method'] ?? null) ? $message['method'] : '';
		$params = is_array($message) && is_array($message['params'] ?? null) ? $message['params'] : [];

		$config = $this->handshake->read();
		// A socket someone else could have made reads as no handshake, as in the Go bridge
		// (private_unix.go): on Linux the gate's directory is in the shared /tmp, and once the app
		// has gone another account can recreate it at the path mcp.json still names.
		if ($config !== null && $config['socket'] !== '' && !self::isPrivate($config['socket'])) {
			$config = null;
		}
		if ($config === null) {
			return $this->unavailable($id, $method, $params, Strings::t('mcp.agent_not_running'));
		}
		$reply = $this->post(Endpoint::at($config['url']), $line, $config['token'], $config['socket']);
		if ($reply === self::TIMED_OUT) {
			return $this->unavailable($id, $method, $params, Strings::t('mcp.agent_timed_out'));
		}
		if ($reply === null) {
			return $this->unavailable($id, $method, $params, Strings::t('mcp.agent_window_closed'));
		}
		[$status, $body] = $reply;
		// Adminer redirects to its login page when the session behind the handshake has expired,
		// and the gate answers 403 once it has no session to lend: say that, whatever the body.
		// Any other error is not the app's answer either, however much it looks like JSON.
		if (($status >= 300 && $status < 400) || $status === 401 || $status === 403) {
			return $this->unavailable($id, $method, $params, Strings::t('mcp.agent_session_expired'));
		}
		if ($status < 200 || $status >= 300) {
			return $this->unavailable($id, $method, $params, Strings::t('mcp.agent_window_closed'));
		}
		// A BOM or a stray newline is not a different answer, and a newline would end the line
		// stdio frames each message by.
		$body = trim((string) preg_replace('~^\xEF\xBB\xBF~', '', $body));
		if ($body === '') {
			// Silence answers a notification (the app's 204), and unavailable() keeps it silent. A
			// request owed an answer that got none — PHP died, the gate had nothing to say — is
			// told so, or the agent waits on its id for good.
			return $this->unavailable($id, $method, $params, Strings::t('mcp.agent_window_closed'));
		}
		// A login page served as 200 is an expired session too, rather than markup to guess at.
		if ($body[0] !== '{' && $body[0] !== '[') {
			return $this->unavailable($id, $method, $params, Strings::t('mcp.agent_session_expired'));
		}
		return $body;
	}

	/** Whether a socket and its directory are this account's alone: not a link, owned by us, and a
	* 0700 directory, as launcher/mcpgate makes it. Without posix (Windows) there is no gate yet. */
	private static function isPrivate(string $socket): bool {
		if (!function_exists('posix_geteuid')) {
			return file_exists($socket);
		}
		$dir = dirname($socket);
		$me = posix_geteuid();
		clearstatcache();
		return !is_link($dir) && is_dir($dir) && @fileowner($dir) === $me && (fileperms($dir) & 0077) === 0
			&& !is_link($socket) && @filetype($socket) === 'socket' && @fileowner($socket) === $me;
	}

	/** POST one message to the launcher's gate, with its token. The gate adds the session.
	*
	* Over the gate's unix socket when the handshake names one: a quit app leaves its port behind
	* for anyone to bind, and whoever did would get the token and the SQL, and could answer. curl
	* rather than PHP's http wrapper, which cannot reach a socket. The timeout is idle, not total,
	* as Go's is: a large result still arriving at a minute has not timed out. No proxy, whatever
	* the environment says, and no redirect followed: the gate is the only place this goes.
	*
	* @return array{int,string}|string|null the status and body; TIMED_OUT; null when unreachable
	*/
	private function post(string $url, string $body, string $token, string $socket): array|string|null {
		$curl = curl_init($url);
		curl_setopt_array($curl, [
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => $body,
			// Expect: empty, or curl holds a body over 1 KiB back for a 100 Continue first.
			CURLOPT_HTTPHEADER => ['Content-Type: application/json', "Authorization: Bearer $token", 'Expect:'],
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_PROXY => '',
			CURLOPT_CONNECTTIMEOUT => 60,
			CURLOPT_LOW_SPEED_LIMIT => 1,
			CURLOPT_LOW_SPEED_TIME => 60,
		]);
		if ($socket !== '') {
			curl_setopt($curl, CURLOPT_UNIX_SOCKET_PATH, $socket);
		}
		$answer = curl_exec($curl);
		if (!is_string($answer)) {
			return curl_errno($curl) === CURLE_OPERATION_TIMEDOUT ? self::TIMED_OUT : null;
		}
		return [(int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), $answer];
	}

	/** Answer when the app cannot be reached, without failing the connection.
	*
	* Failing `initialize` is what a client reports as a dead server: it prints the JSON-RPC code
	* and drops the message, so the sentence explaining exactly what to do never reaches anyone.
	* The observed symptom was a bare "-32000" and a server listed as failed, for the entirely
	* ordinary case of the feature not being switched on yet.
	*
	* So the handshake always succeeds — we are a working server whose backend happens to be
	* unavailable — and the explanation is delivered as a tool *result* instead, which clients
	* render into the conversation where somebody will read it.
	*
	* tools/list advertises the real tools, not a placeholder. A single `need_attention_read_me`
	* was the previous answer, and a model seeing no database tools concluded the server was not
	* connected and wrote its own bridge to the database. The real list also means opening the app
	* is enough: the next call goes through, with no reconnect to remember.
	*
	* @param array<mixed> $params
	*/
	private function unavailable(mixed $id, string $method, array $params, string $message): ?string {
		if ($id === null) {
			return null; // a notification: answered with silence whatever the state
		}
		return match ($method) {
			'initialize' => $this->result($id, [
				'protocolVersion' => Server::negotiate($params),
				'capabilities' => ['tools' => new \stdClass()],
				'serverInfo' => ['name' => 'adminer-desktop', 'version' => 'offline'],
				'instructions' => Server::INSTRUCTIONS,
			]),
			'tools/list' => $this->result($id, ['tools' => $this->catalogue()]),
			'ping' => $this->result($id, new \stdClass()),
			// What Server answers once the app is up: a dual-era client reads -32601 as "legacy" and
			// falls back to initialize. A tool result here would read as a discover that succeeded.
			'server/discover' => (string) json_encode(['jsonrpc' => '2.0', 'id' => $id, 'error' => [
				'code' => -32601,
				'message' => Strings::t('mcp.agent_unknown_method') . ': server/discover',
			]]),
			// tools/call and anything else: an error the model can read and repeat to the user.
			default => $this->result($id, [
				'content' => [['type' => 'text', 'text' => $message]],
				'isError' => true,
			]),
		};
	}

	/** The tools as the app would describe them, from settings.json beside the handshake.
	*
	* Not the defaults: a client keeps the list it got at connect, so the descriptions given while
	* the app was closed are the ones it still trusts once the app is up — with writes on, a promise
	* that everything is rolled back.
	*
	* @return list<array<string,mixed>>
	*/
	private function catalogue(): array {
		$path = $this->handshake->path();
		$settings = new UserSettings($path !== null ? dirname($path) : null);
		return Server::catalogue((bool) $settings->get(SettingKey::McpWholeValues, false), (bool) $settings->get(SettingKey::McpWrite, false));
	}

	/** @param array<string,mixed>|\stdClass $result */
	private function result(mixed $id, array|\stdClass $result): string {
		return (string) json_encode(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
	}
}
