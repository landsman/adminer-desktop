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
* The transport is injectable so the loop can be tested without a server: everything
* interesting here is the mapping from a failed request to something an agent can act on, and
* that is precisely the part an end-to-end check cannot provoke on demand.
*/
class Stdio {
	private Handshake $handshake;

	/** @var callable(string,string,string):(string|false) */
	private $send;

	/** @param (callable(string,string,string):(string|false))|null $send url, body, token */
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
			return $this->unavailable($id, $method, Strings::t('mcp.agent_not_running'));
		}
		$body = ($this->send)(Endpoint::at($config['url']), $line, $config['token']);

		if ($body === false) {
			return $this->unavailable($id, $method, Strings::t('mcp.agent_window_closed'));
		}
		if ($body === '') {
			return null; // the app answered 204: a notification, and nothing to forward
		}
		// Adminer answers HTML when the session behind the handshake has expired. Say that,
		// rather than handing the agent a page of markup to guess at.
		if ($body[0] !== '{' && $body[0] !== '[') {
			return $this->unavailable($id, $method, Strings::t('mcp.agent_session_expired'));
		}
		return $body;
	}

	/** POST one message to the launcher's gate, with its token. The gate adds the session.
	*
	* @return string|false
	*/
	private function post(string $url, string $body, string $token) {
		$context = stream_context_create(['http' => [
			'method' => 'POST',
			'header' => ['Content-Type: application/json', "Authorization: Bearer $token"],
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
	* render into the conversation where somebody will read it.
	*
	* tools/list advertises the real tools, not a placeholder. A single `need_attention_read_me`
	* was the previous answer, and a model seeing no database tools concluded the server was not
	* connected and wrote its own bridge to the database. The real list also means opening the app
	* is enough: the next call goes through, with no reconnect to remember.
	*/
	private function unavailable(mixed $id, string $method, string $message): ?string {
		if ($id === null) {
			return null; // a notification: answered with silence whatever the state
		}
		return match ($method) {
			'initialize' => $this->result($id, [
				'protocolVersion' => Server::PROTOCOL,
				'capabilities' => ['tools' => new \stdClass()],
				'serverInfo' => ['name' => 'adminer-desktop', 'version' => 'offline'],
				'instructions' => Server::INSTRUCTIONS,
			]),
			'tools/list' => $this->result($id, ['tools' => $this->catalogue()]),
			'ping' => $this->result($id, new \stdClass()),
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
