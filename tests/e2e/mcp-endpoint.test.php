<?php
declare(strict_types=1);

/** Desktop\Mcp\Endpoint's decisions, with no adminer and no database.
 *
 * mcp.test.php drives the endpoint through a real window; this covers the two judgements behind
 * it, which are the ones that have already been wrong once.
 *
 * The URL is the important one. Recording adminer.php instead of Adminer\ME cost a round of
 * debugging: adminer takes the server and database from the query string rather than the
 * session, so the agent's request reached an adminer with no driver at all and every tool call
 * died on a null. That is a string-building mistake, and it deserves a check that does not need
 * a database to catch it.
 *
 * Run standalone: ./bin/frankenphp php-cli tests/e2e/mcp-endpoint.test.php
 */

require dirname(__DIR__, 2) . '/app/vendor/autoload.php';

use Desktop\Mcp\Endpoint;
use Desktop\UserSettings;

$failures = [];
/** @param mixed $actual */
$is = function (string $what, $actual, $expected) use (&$failures): void {
    if ($actual !== $expected) {
        $failures[] = "$what: expected " . json_encode($expected) . ', got ' . json_encode($actual);
    }
};

// No data dir: UserSettings persists nothing and Handshake writes nothing, which is all this
// needs — none of the methods under test touch either.
$endpoint = new Endpoint(new UserSettings(null));

$me = 'adminer.php?pgsql=127.0.0.1%3A55432&username=postgres&db=demo&';
$server = ['HTTP_HOST' => '127.0.0.1:18080', 'SCRIPT_NAME' => '/adminer.php'];

// 1. The connection has to survive into the recorded URL, or the agent reaches a driverless
//    adminer. This is the regression.
$url = $endpoint->url($server, true, $me);
$is('records the connected url', $url, 'http://127.0.0.1:18080/' . $me);
if ($url === null || !str_contains($url, 'db=demo')) {
    $failures[] = 'the recorded url lost the database: ' . json_encode($url);
}

// 2. Not connected is not logged in: nothing worth borrowing, so nothing recorded.
$is('records nothing when disconnected', $endpoint->url($server, false, $me), null);

// 3. No host, nothing to build an absolute URL from.
$is('records nothing without a host', $endpoint->url(['SCRIPT_NAME' => '/adminer.php'], true, $me), null);

// 4. Served from a subdirectory, the URL keeps it — and does not double the slash.
$is(
    'keeps a subdirectory',
    $endpoint->url(['HTTP_HOST' => 'h', 'SCRIPT_NAME' => '/tools/adminer.php'], true, $me),
    'http://h/tools/' . $me,
);

// 5. Windows can hand back a backslashed SCRIPT_NAME; those are not URL separators.
$is(
    'normalises windows separators',
    $endpoint->url(['HTTP_HOST' => 'h', 'SCRIPT_NAME' => '\\tools\\adminer.php'], true, $me),
    'http://h/tools/' . $me,
);

// 6. A request that arrives with a stale handshake must be told so in JSON. Falling through
//    would reach Server and crash on a null driver, and answering HTML would leave the agent
//    parsing a login page.
$answer = $endpoint->answer('{"jsonrpc":"2.0","id":1,"method":"tools/list"}', false);
$decoded = json_decode((string) $answer, true);
$is('disconnected answers a json-rpc error', $decoded['error']['code'] ?? null, -32000);
if (!str_contains((string) ($decoded['error']['message'] ?? ''), 'Log in again')) {
    $failures[] = 'the disconnected message does not say what to do: ' . json_encode($answer);
}

if ($failures !== []) {
    echo implode("\n", $failures), "\n";
    echo 'mcp-endpoint: ' . count($failures) . " failure(s)\n";
    exit(1);
}
echo "mcp-endpoint ok\n";
exit(0);
