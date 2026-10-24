<?php
declare(strict_types=1);

/** A stand-in for the running app, for the bridge scenarios.
*
* The bridge's interesting behaviour is what it says when the app answers oddly, and a test
* cannot make a real window return HTML or an empty body on demand. This answers every POST
* with one canned body, and records what was asked so the scenario can check the URL, the token
* and the message that went through.
*
*     php-cli mcp-app.php <socket> <mode> <record file>
*
* On a unix socket, as the launcher's gate is reached: the bridges post nowhere else.
*
* Printed "listening" once it is bound, the way slow-server.php does, so the scenario can wait
* for it rather than guess.
*
* Modes: app (JSON for a request, empty for a notification — what Adminer does), json, nothing,
* login, padded (the JSON behind a BOM and around whitespace), error (JSON with a 502).
*/

[, $socket, $mode, $record] = $argv;
$server = stream_socket_server("unix://$socket", $errno, $error);
if ($server === false) {
	fwrite(STDERR, "mcp-app: cannot listen on $socket: $error\n");
	exit(1);
}
echo "listening\n";
fflush(STDOUT);

/** One response body for a request, or null for an empty one. */
function answer(string $mode, string $body): ?string
{
	return match ($mode) {
		'app' => str_contains($body, '"id"') ? '{"jsonrpc":"2.0","id":7,"result":{"tools":[]}}' : null,
		'json', 'error' => '{"jsonrpc":"2.0","id":7,"result":{"tools":[]}}',
		'padded' => "\xEF\xBB\xBF \n" . '{"jsonrpc":"2.0","id":7,"result":{"tools":[]}}' . "\n",
		'login' => '<!doctype html><title>Login</title>',
		'nothing' => null,
		default => throw new RuntimeException("no such mode: $mode"),
	};
}

while (($client = @stream_socket_accept($server, -1)) !== false) {
	$head = '';
	while (($line = fgets($client)) !== false && $line !== "\r\n") {
		$head .= $line;
	}
	$length = preg_match('~^Content-Length:\s*(\d+)~mi', $head, $m) ? (int) $m[1] : 0;
	$body = $length > 0 ? (string) fread($client, $length) : '';
	$authorization = preg_match('~^Authorization:\s*(.+?)\r?$~mi', $head, $m) ? trim($m[1]) : '';
	$uri = preg_match('~^POST\s+(\S+)~', $head, $m) ? $m[1] : '';

	file_put_contents($record, (string) json_encode([
		'uri' => $uri,
		'authorization' => $authorization,
		'body' => $body,
	]) . "\n", FILE_APPEND);

	$answer = answer($mode, $body);
	$payload = $answer ?? '';
	$type = $mode === 'login' ? 'text/html' : 'application/json';
	$status = $mode === 'error' ? '502 Bad Gateway' : '200 OK';
	fwrite($client, "HTTP/1.1 $status\r\nContent-Type: $type\r\nContent-Length: " . strlen($payload) . "\r\nConnection: close\r\n\r\n$payload");
	fclose($client);
}
