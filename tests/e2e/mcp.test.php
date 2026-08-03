<?php
declare(strict_types=1);

/** The MCP endpoint, end to end: an agent's stdio server reaching the database this window is
 * logged into.
 *
 * There is no browser here — the thing under test is an HTTP session being borrowed by a second
 * process, so the check logs in with curl and then drives app/mcp.php exactly as an agent
 * would, over its stdin and stdout.
 *
 * The assertion that earns its keep is the last one. execute_query runs inside a transaction
 * that is always rolled back, and "always" is a claim about a security boundary: if it ever
 * stops being true, an agent that was told it had read-only access can quietly write. So the
 * check inserts a row through the tool and then looks for it with a connection of its own.
 *
 * Run standalone: ./bin/frankenphp php-cli tests/e2e/mcp.test.php
 */

require __DIR__ . '/fixture.php';

use Symfony\Component\Process\Process;

$fix = e2e_boot(18082);
$failures = [];

/** Post one JSON-RPC message straight at the app, with the session's cookies. */
$rpc = function (array $message, array $cookies, string $url): array {
    $ch = curl_init($url . (str_contains($url, '?') ? '&' : '?') . 'mcp=1');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($message),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_COOKIE => implode('; ', array_map(fn($k, $v) => "$k=$v", array_keys($cookies), $cookies)),
    ]);
    $body = (string) curl_exec($ch);
    return [json_decode($body, true), $body];
};

try {
    $base = $fix['base'];
    $data = $fix['data'];

    // Feature on. Written straight to settings.json rather than through the dialog: check.sh
    // already proves the POST path, and this check is about what happens after it.
    file_put_contents($data . '/settings.json', (string) json_encode(['mcp' => true]));
    @unlink($data . '/mcp.json');

    // Log in with curl and keep the cookies — the same borrow the shim performs.
    $cookies = [];
    $collect = function ($ch) use (&$cookies): void {
        foreach ((array) curl_getinfo($ch, CURLINFO_COOKIELIST) as $line) {
            $f = explode("\t", $line);
            if (count($f) >= 7) {
                $cookies[$f[5]] = $f[6];
            }
        }
    };
    $ch = curl_init($base);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => '']);
    $page = (string) curl_exec($ch);
    $collect($ch);
    preg_match("~name='token' value='([^']*)'~", $page, $m);

    $ch = curl_init($base);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEFILE => '',
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_POST => true,
        CURLOPT_COOKIE => implode('; ', array_map(fn($k, $v) => "$k=$v", array_keys($cookies), $cookies)),
        CURLOPT_POSTFIELDS => http_build_query([
            'auth' => [
                'driver' => 'pgsql', 'server' => '127.0.0.1:' . $fix['pgPort'],
                'username' => 'postgres', 'password' => 'demo', 'db' => 'demo',
            ],
            'token' => $m[1] ?? '',
        ]),
    ]);
    $after = (string) curl_exec($ch);
    $collect($ch);
    if (!str_contains($after, 'Logout') && !str_contains($after, 'logout=1')) {
        throw new RuntimeException('could not log in with curl — the rest of the check would be meaningless');
    }

    // A connected request, so the handshake records a URL that carries the connection. A bare
    // adminer.php would reach an adminer with no driver, which is the bug this check caught.
    $ch = curl_init($fix['select']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIE => implode('; ', array_map(fn($k, $v) => "$k=$v", array_keys($cookies), $cookies)),
    ]);
    curl_exec($ch);

    // 1. The handshake exists, and points at this window with this session's cookies.
    $mcpUrl = $base;
    if (!is_file($data . '/mcp.json')) {
        $failures[] = 'no handshake written after a connected request';
    } else {
        $handshake = json_decode((string) file_get_contents($data . '/mcp.json'), true);
        $mcpUrl = is_string($handshake['url'] ?? null) ? $handshake['url'] : $base;
        if (!isset($handshake['url']) || !str_contains((string) $handshake['url'], '18082')) {
            $failures[] = 'handshake url does not point at the running app: ' . json_encode($handshake['url'] ?? null);
        }
        if (!isset($handshake['cookies']['adminer_sid'])) {
            $failures[] = 'handshake carries no adminer_sid, so nothing could borrow the session';
        }
        $perms = substr(sprintf('%o', (int) fileperms($data . '/mcp.json')), -3);
        if ($perms !== '600') {
            $failures[] = "handshake is $perms, expected 600 — it holds session cookies";
        }
    }

    // 2. initialize and tools/list, straight at the endpoint.
    [$init] = $rpc(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => []], $cookies, $mcpUrl);
    $ours = $init['result']['protocolVersion'] ?? '';
    if ($ours === '') {
        $failures[] = 'initialize returned no protocolVersion: ' . json_encode($init);
    }

    // Negotiation, both directions. A client newer than us must be told what we actually
    // implement rather than agreeably echoed — otherwise we promise whatever that revision
    // added. A client older than us gets met where it is.
    [$newer] = $rpc([
        'jsonrpc' => '2.0', 'id' => 11, 'method' => 'initialize',
        'params' => ['protocolVersion' => '2099-01-01'],
    ], $cookies, $mcpUrl);
    if (($newer['result']['protocolVersion'] ?? '') !== $ours) {
        $failures[] = 'a newer client was echoed its own version instead of ours: '
            . json_encode($newer['result']['protocolVersion'] ?? null);
    }

    [$older] = $rpc([
        'jsonrpc' => '2.0', 'id' => 12, 'method' => 'initialize',
        'params' => ['protocolVersion' => '2024-11-05'],
    ], $cookies, $mcpUrl);
    if (($older['result']['protocolVersion'] ?? '') !== '2024-11-05') {
        $failures[] = 'an older client was not met at its own version: '
            . json_encode($older['result']['protocolVersion'] ?? null);
    }

    [$list] = $rpc(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => []], $cookies, $mcpUrl);
    $names = array_column($list['result']['tools'] ?? [], 'name');
    foreach (['current_connection', 'list_tables', 'describe_table', 'preview_table_data', 'execute_query'] as $want) {
        if (!in_array($want, $names, true)) {
            $failures[] = "tools/list is missing $want (got: " . implode(', ', $names) . ')';
        }
    }

    // 3. A tool call reaches the seeded database.
    [$tables] = $rpc([
        'jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call',
        'params' => ['name' => 'list_tables', 'arguments' => []],
    ], $cookies, $mcpUrl);
    $text = $tables['result']['content'][0]['text'] ?? '';
    if (!str_contains($text, 'users')) {
        $failures[] = 'list_tables did not mention the seeded users table: ' . substr($text, 0, 200);
    }

    // 4. The shim: the same call, but through stdin/stdout as an agent would run it.
    $shim = new Process(
        [$fix['root'] . '/bin/frankenphp', 'php-cli', $fix['root'] . '/app/mcp.php'],
        null,
        ['ADMINER_DESKTOP_DATA' => $data],
    );
    $shim->setInput(implode("\n", [
        (string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => []]),
        (string) json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => [
            'name' => 'execute_query',
            'arguments' => ['sql' => 'SELECT count(*) AS n FROM users'],
        ]]),
    ]) . "\n");
    $shim->run();
    $out = $shim->getOutput();
    if (!str_contains($out, 'protocolVersion')) {
        $failures[] = 'the stdio shim did not answer initialize: ' . substr($out . $shim->getErrorOutput(), 0, 300);
    }
    // Decode rather than string-match: the tool's payload is JSON *inside* a JSON string, so a
    // naive contains('"n"') looks for a quote that is escaped by the time it reaches the wire.
    $rows = null;
    foreach (explode("\n", trim($out)) as $line) {
        $message = json_decode($line, true);
        if (($message['id'] ?? null) === 2) {
            $payload = json_decode($message['result']['content'][0]['text'] ?? '', true);
            $rows = $payload['rows'] ?? null;
        }
    }
    if (!is_array($rows) || count($rows) !== 1 || !isset($rows[0]['n'])) {
        $failures[] = 'the stdio shim did not return the counted row: ' . substr($out . $shim->getErrorOutput(), 0, 300);
    }

    // 5. THE ONE THAT MATTERS: a write through execute_query must leave nothing behind.
    $marker = 'mcp-rollback-probe';
    $rpc([
        'jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call',
        'params' => ['name' => 'execute_query', 'arguments' => [
            'sql' => "INSERT INTO users (name) VALUES ('$marker')",
        ]],
    ], $cookies, $mcpUrl);

    // A different marker: the cleanup below must not also delete the row the MCP call
    // might have left behind, or the final count reads 0 whether rollback worked or not.
    $probe = $marker . '-direct';
    // Prove the INSERT was well-formed first, or "no row survived" would pass just as happily
    // for a statement that never ran. Same SQL, straight at postgres: it must land, and then we
    // take it back out.
    $direct = new Process([
        'docker', 'exec', 'adminer-demo-pg', 'psql', '-U', 'postgres', '-d', 'demo',
        '-tAc', "INSERT INTO users (name) VALUES ('$probe'); SELECT count(*) FROM users WHERE name = '$probe'",
    ]);
    $direct->run();
    // psql -tAc with two statements prints a line per statement; the count is the last one.
    $lines = array_values(array_filter(array_map('trim', explode("\n", $direct->getOutput())), 'strlen'));
    if (end($lines) !== '1') {
        $failures[] = 'the probe INSERT is not valid SQL, so the rollback assertion below proves nothing: '
            . trim($direct->getErrorOutput() ?: $direct->getOutput());
    }
    (new Process([
        'docker', 'exec', 'adminer-demo-pg', 'psql', '-U', 'postgres', '-d', 'demo',
        '-tAc', "DELETE FROM users WHERE name = '$probe'",
    ]))->run();

    // The answer must also *say* it was rolled back. RETURNING makes an INSERT produce an
    // ordinary result set, so without this the caller sees rows and concludes it wrote — which
    // is exactly what happened in use, and is worse than refusing the write outright.
    [$written] = $rpc([
        'jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/call',
        'params' => ['name' => 'execute_query', 'arguments' => [
            'sql' => "INSERT INTO users (name) VALUES ('$marker-returning') RETURNING id",
        ]],
    ], $cookies, $mcpUrl);
    $payload = json_decode($written['result']['content'][0]['text'] ?? '', true);
    if (($payload['rolled_back'] ?? null) !== true) {
        $failures[] = 'a write answered without saying it was rolled back: ' . json_encode($payload);
    }

    $check = new Process([
        'docker', 'exec', 'adminer-demo-pg', 'psql', '-U', 'postgres', '-d', 'demo',
        '-tAc', "SELECT count(*) FROM users WHERE name LIKE '$marker%'",
    ]);
    $check->run();
    $left = trim($check->getOutput());
    if ($left !== '0') {
        $failures[] = "READ-ONLY BROKEN: the INSERT survived (found $left row(s) named $marker)";
    }

    // 6. Turning it off must retract the handshake, not just stop honouring it.
    file_put_contents($data . '/settings.json', (string) json_encode(['mcp' => false]));
    $ch = curl_init($base);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIE => implode('; ', array_map(fn($k, $v) => "$k=$v", array_keys($cookies), $cookies)),
    ]);
    curl_exec($ch);
    if (is_file($data . '/mcp.json')) {
        $failures[] = 'handshake still on disk after the setting was turned off';
    }
} catch (\Throwable $e) {
    $failures[] = 'mcp: ' . $e->getMessage();
}

@unlink($fix['data'] . '/settings.json');
@unlink($fix['data'] . '/mcp.json');
e2e_done($fix['server'], $failures, 'mcp');
