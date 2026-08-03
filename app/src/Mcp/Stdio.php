<?php
declare(strict_types=1);

namespace Desktop\Mcp;

/** The bridge an agent talks to: newline-delimited JSON-RPC on stdio, the running window on HTTP.
*
* MCP's stdio transport expects to *start* its server as a child process. This app is already
* running, on a port the launcher picks fresh every start, so nothing an agent spawns could know
* where to reach it. This class is the piece in between: per message it reads the handshake for
* the current URL and session cookies, forwards the message there, and writes the answer back.
*
* Per message, not once at startup, because the agent may have spawned us before the app was
* running and will certainly outlive a restart of it.
*
* The transport is injectable so the loop can be tested without a server: everything
* interesting here is the mapping from a failed request to something an agent can act on, and
* that is precisely the part an end-to-end check cannot provoke on demand.
*/
class Stdio {
	private Handshake $handshake;

	/** @var callable(string,string,array<string,string>):(string|false) */
	private $send;

	/** @param (callable(string,string,array<string,string>):(string|false))|null $send url, body, cookies */
	function __construct(?Handshake $handshake = null, ?callable $send = null) {
		$this->handshake = $handshake ?? new Handshake();
		$this->send = $send ?? [$this, 'post'];
	}

	/** Pump messages until stdin closes.
	*
	* @param resource $in
	* @param resource $out
	*/
	function run($in, $out): void {
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

		$config = $this->handshake->read();
		if ($config === null) {
			return $this->unavailable($id, $method, 'Adminer Desktop is not running, or database access for agents is switched off in its settings. Open the app, log in, and switch it on under Settings > AI access — then reconnect this server, because the tool list is read once when the connection opens.');
		}
		$url = $config['url'] . (str_contains($config['url'], '?') ? '&' : '?') . 'mcp=1';
		$body = ($this->send)($url, $line, $config['cookies']);

		if ($body === false) {
			return $this->unavailable($id, $method, 'Adminer Desktop stopped answering — the window was probably closed. Open it again and reconnect this server; the registration itself keeps working.');
		}
		if ($body === '') {
			return null; // the app answered 204: a notification, and nothing to forward
		}
		// Adminer answers HTML when the session behind the handshake has expired. Say that,
		// rather than handing the agent a page of markup to guess at.
		if ($body[0] !== '{' && $body[0] !== '[') {
			return $this->unavailable($id, $method, 'The Adminer Desktop session has expired. Log in to the database again in the app, then reconnect this server.');
		}
		return $body;
	}

	/** POST one message to the window, with the session's cookies.
	*
	* @param array<string,string> $cookies
	* @return string|false
	*/
	private function post(string $url, string $body, array $cookies) {
		$pairs = [];
		foreach ($cookies as $name => $value) {
			$pairs[] = rawurlencode((string) $name) . '=' . rawurlencode($value);
		}
		$context = stream_context_create(['http' => [
			'method' => 'POST',
			'header' => ['Content-Type: application/json', 'Cookie: ' . implode('; ', $pairs)],
			'content' => $body,
			'timeout' => 60,
			'ignore_errors' => true, // read the body of a 4xx/5xx rather than turning it into false
		]]);
		return @file_get_contents($url, false, $context);
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
	* render into the conversation where somebody will read it. tools/list answers with nothing
	* rather than an error for the same reason: an empty toolbox is a state, not a breakage.
	*/
	private function unavailable(mixed $id, string $method, string $message): ?string {
		if ($id === null) {
			return null; // a notification: answered with silence whatever the state
		}
		return match ($method) {
			'initialize' => $this->result($id, [
				'protocolVersion' => '2025-11-25',
				'capabilities' => ['tools' => new \stdClass()],
				'serverInfo' => ['name' => 'adminer-desktop', 'version' => 'offline'],
			]),
			// One tool, whose name and description are the whole message. An empty list was the
			// first attempt and it is a dead end: a client shows "connected, no tools" and there
			// is then nothing to call, so the explanation can never be reached. This puts the
			// reason in the tool list itself, where it is visible without calling anything, and
			// still answers with it if the model does call.
			'tools/list' => $this->result($id, ['tools' => [[
				'name' => 'need_attention_read_me',
				'description' => $message,
				'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
			]]]),
			'ping' => $this->result($id, new \stdClass()),
			// tools/call and anything else: an error the model can read and repeat to the user.
			default => $this->result($id, [
				'content' => [['type' => 'text', 'text' => $message]],
				'isError' => true,
			]),
		};
	}

	/** @param array<string,mixed>|\stdClass $result */
	private function result(mixed $id, array|\stdClass $result): string {
		return (string) json_encode(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
	}
}
