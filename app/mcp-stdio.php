<?php
declare(strict_types=1);

/** The MCP server an agent spawns: stdin and stdout here, the running app over HTTP there.
*
* MCP's stdio transport is newline-delimited JSON-RPC, and an agent starts its server as a
* child process. Neither fact fits a desktop app that is already running and whose port changes
* every launch — so this is the small piece in between. It reads Desktop\Mcp\Handshake to learn
* where this window is and which cookies belong to its session, and replays each message there.
*
* A bare entry like api.php: served by nothing, run by `frankenphp php-cli`, so it turns the
* autoloader on itself.
*
* Register it with the agent once and it keeps working across restarts, because the thing that
* changes — the port — is read fresh on every message rather than baked into the config:
*
*     claude mcp add adminer -- /path/to/frankenphp php-cli /path/to/app/mcp-stdio.php
*/

require_once __DIR__ . "/vendor/autoload.php";

use Desktop\Mcp\Handshake;

/** A JSON-RPC error the agent can read, for the failures that happen before we ever reach the
* app — no window open, or the feature switched off. Anything with an id gets an answer;
* notifications stay silent, as the protocol requires. */
$fail = function (mixed $id, string $message): void {
	if ($id === null) {
		return;
	}
	echo json_encode(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32000, 'message' => $message]]), "\n";
};

$handshake = new Handshake();

while (($line = fgets(STDIN)) !== false) {
	$line = trim($line);
	if ($line === '') {
		continue;
	}
	$message = json_decode($line, true);
	$id = is_array($message) ? ($message['id'] ?? null) : null;

	// Read the handshake per message, not once at startup: the app may not have been running
	// when the agent spawned us, and the port changes with every launch of it.
	$config = $handshake->read();
	if ($config === null) {
		$fail($id, 'Adminer Desktop is not running, or database access for agents is switched off in its settings. Open the app, log in, and enable it under Settings.');
		continue;
	}

	$cookies = [];
	foreach ($config['cookies'] as $name => $value) {
		$cookies[] = rawurlencode((string) $name) . '=' . rawurlencode($value);
	}
	$context = stream_context_create(['http' => [
		'method' => 'POST',
		'header' => [
			'Content-Type: application/json',
			'Cookie: ' . implode('; ', $cookies),
		],
		'content' => $line,
		'timeout' => 60,
		'ignore_errors' => true, // read the body of a 4xx/5xx rather than turning it into false
	]]);
	$url = $config['url'] . (str_contains($config['url'], '?') ? '&' : '?') . 'mcp=1';
	$body = @file_get_contents($url, false, $context);

	if ($body === false) {
		$fail($id, 'Adminer Desktop stopped answering — the window was probably closed.');
		continue;
	}
	if ($body === '') {
		continue; // a notification: the app answered 204 and there is nothing to forward
	}
	// The app answers the login page as HTML when the session behind the handshake has expired.
	// Say that, rather than handing the agent a page of markup to guess at.
	if ($body[0] !== '{' && $body[0] !== '[') {
		$fail($id, 'The Adminer Desktop session has expired. Log in again in the app.');
		continue;
	}
	echo $body, "\n";
}
