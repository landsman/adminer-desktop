<?php
declare(strict_types=1);

/** Desktop\Mcp\Stdio: the bridge's own behaviour, with no app and no database.
 *
 * mcp.test.php covers the happy path against a real window. This covers what that check cannot
 * provoke on demand — the app not running, the window closed mid-session, an expired login —
 * because each is a message an agent has to be able to act on, and each is a one-line mistake
 * away from being silence or a page of HTML instead.
 *
 * No fixture, no docker, no server: the transport is a closure and the handshake is a temp dir.
 * It lives here because tests/e2e is where run.php's glob looks, and one file is not worth a
 * second harness.
 *
 * Run standalone: ./bin/frankenphp php-cli tests/e2e/mcp-bridge.test.php
 */

require dirname(__DIR__, 2) . '/app/vendor/autoload.php';

use Desktop\Mcp\Handshake;
use Desktop\Mcp\Stdio;

$failures = [];
$dir = sys_get_temp_dir() . '/adminer-mcp-bridge-' . getmypid();
@mkdir($dir, 0700, true);
$handshake = new Handshake($dir);

/** @param mixed $actual */
$is = function (string $what, $actual, $expected) use (&$failures): void {
    if ($actual !== $expected) {
        $failures[] = "$what: expected " . json_encode($expected) . ', got ' . json_encode($actual);
    }
};
$contains = function (string $what, ?string $actual, string $needle) use (&$failures): void {
    if ($actual === null || !str_contains($actual, $needle)) {
        $failures[] = "$what: expected something containing " . json_encode($needle) . ', got ' . json_encode($actual);
    }
};

// 0. The bridge has to find the data dir with no environment of ours. An agent spawns it as a
//    bare child process, so if this ever depends on ADMINER_DESKTOP_DATA again it reports the
//    app as not running while it is open in front of you — which is exactly what it did, and
//    the end-to-end check could not see it because that check sets the variable itself.
putenv('ADMINER_DESKTOP_DATA');
$found = (new Handshake())->path();
if ($found === null) {
    $failures[] = 'with no ADMINER_DESKTOP_DATA the bridge finds no data dir at all';
} elseif (!str_ends_with($found, 'Adminer Desktop/mcp.json')) {
    $failures[] = 'the fallback data dir is not the launcher’s: ' . $found;
}

$request = (string) json_encode(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/list']);
$notification = (string) json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
$unreachable = fn(): bool => false;

// 1. No handshake at all: the app is not running, or the feature is off. The connection must
//    still come up — failing initialize is reported by clients as a dead server, printing the
//    JSON-RPC code and dropping the message, so the sentence saying what to do never arrives.
$handshake->clear();
$stdio = new Stdio($handshake, $unreachable);
$init = json_decode((string) $stdio->exchange((string) json_encode(
    ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize'],
)), true);
$is('initialize succeeds while unavailable', $init['result']['protocolVersion'] ?? null, '2025-11-25');
// Not an empty list: "connected, no tools" leaves nothing to call, so the reason can never be
// reached. The one tool advertised is the reason, readable in the client's tool list.
$list = json_decode((string) $stdio->exchange($request), true);
$is('advertises exactly one tool while unavailable', count($list['result']['tools'] ?? []), 1);
$is('named for the state', $list['result']['tools'][0]['name'] ?? null, 'need_attention_read_me');
$contains('described with what to do', $list['result']['tools'][0]['description'] ?? null, 'AI access');

// The explanation belongs where a client renders it: a tool result, not a transport error.
$call = json_decode((string) $stdio->exchange((string) json_encode(
    ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'list_tables']],
)), true);
$is('a call while unavailable is a tool error', $call['result']['isError'] ?? null, true);
$contains('and it says what to do', $call['result']['content'][0]['text'] ?? null, 'AI access');

// 2. Same, but the message was a notification. JSON-RPC answers those with silence — an error
//    here would be a protocol violation that strict clients complain about.
$is('notification with no handshake stays silent', $stdio->exchange($notification), null);

// From here on there is a handshake to read.
$handshake->write('http://127.0.0.1:1/adminer.php?pgsql=x&db=demo&', ['adminer_sid' => 'abc']);

// 3. The window was closed: the transport fails outright.
$closed = json_decode((string) (new Stdio($handshake, $unreachable))->exchange((string) json_encode(
    ['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call', 'params' => ['name' => 'list_tables']],
)), true);
$contains('window closed', $closed['result']['content'][0]['text'] ?? null, 'stopped answering');

// 4. A 204: the app had nothing to say, and neither have we.
$is('empty body forwards nothing', (new Stdio($handshake, fn(): string => ''))->exchange($request), null);

// 5. HTML back means the session behind the handshake expired. The agent must be told that,
//    not handed a login page to parse.
$html = new Stdio($handshake, fn(): string => "<!doctype html><title>Login</title>");
$expired = json_decode((string) $html->exchange((string) json_encode(
    ['jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/call', 'params' => ['name' => 'list_tables']],
)), true);
$contains('expired session', $expired['result']['content'][0]['text'] ?? null, 'expired');

// 6. A JSON answer is forwarded verbatim — the bridge must not reshape what the server said.
$answer = '{"jsonrpc":"2.0","id":7,"result":{"tools":[]}}';
$is('json forwarded unchanged', (new Stdio($handshake, fn(): string => $answer))->exchange($request), $answer);

// 7. The URL it posts to carries the connection from the handshake, plus mcp=1 — the bug that
//    made every tool call reach an adminer with no driver.
$seen = null;
$spy = new Stdio($handshake, function (string $url, string $body, array $cookies) use (&$seen): string {
    $seen = ['url' => $url, 'body' => $body, 'cookies' => $cookies];
    return '{}';
});
$spy->exchange($request);
$contains('posts with mcp=1', $seen['url'] ?? null, 'mcp=1');
$contains('posts to the connected url', $seen['url'] ?? null, 'db=demo');
$is('forwards the message unchanged', $seen['body'] ?? null, $request);
$is('replays the session cookie', $seen['cookies']['adminer_sid'] ?? null, 'abc');

// 8. run() pumps a stream: blank lines skipped, one answer per request, silence stays silent.
$in = fopen('php://memory', 'r+');
fwrite($in, $request . "\n\n" . $notification . "\n" . $request . "\n");
rewind($in);
$out = fopen('php://memory', 'r+');
// Like the app: a notification is answered 204, i.e. an empty body. The bridge does not parse
// for that itself — it forwards everything and lets the server decide — so a stub that answers
// every message alike would prove nothing about the silence.
$likeTheApp = function (string $url, string $body) use ($answer): string {
    $message = json_decode($body, true);
    return isset($message['id']) ? $answer : '';
};
(new Stdio($handshake, $likeTheApp))->run($in, $out);
rewind($out);
$written = (string) stream_get_contents($out);
$is('run answers each request once, notifications never', substr_count($written, '"result"'), 2);
$is('run terminates every line', substr_count($written, "\n"), 2);

array_map('unlink', glob("$dir/*") ?: []);
@rmdir($dir);

if ($failures !== []) {
    echo implode("\n", $failures), "\n";
    echo 'mcp-bridge: ' . count($failures) . " failure(s)\n";
    exit(1);
}
echo "mcp-bridge ok\n";
exit(0);
