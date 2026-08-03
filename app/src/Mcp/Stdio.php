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

		$config = $this->handshake->read();
		if ($config === null) {
			return $this->error($id, 'Adminer Desktop is not running, or database access for agents is switched off in its settings. Open the app, log in, and enable it under Settings.');
		}
		$url = $config['url'] . (str_contains($config['url'], '?') ? '&' : '?') . 'mcp=1';
		$body = ($this->send)($url, $line, $config['cookies']);

		if ($body === false) {
			return $this->error($id, 'Adminer Desktop stopped answering — the window was probably closed.');
		}
		if ($body === '') {
			return null; // the app answered 204: a notification, and nothing to forward
		}
		// Adminer answers HTML when the session behind the handshake has expired. Say that,
		// rather than handing the agent a page of markup to guess at.
		if ($body[0] !== '{' && $body[0] !== '[') {
			return $this->error($id, 'The Adminer Desktop session has expired. Log in again in the app.');
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

	/** A JSON-RPC error for the failures that happen before the app is ever reached. */
	private function error(mixed $id, string $message): ?string {
		if ($id === null) {
			return null;
		}
		return (string) json_encode([
			'jsonrpc' => '2.0',
			'id' => $id,
			'error' => ['code' => -32000, 'message' => $message],
		]);
	}
}
