<?php
declare(strict_types=1);

/** Desktop\Mcp\RequestLog: the daily file, and what it keeps.
 *
 * Rotation and retention only misbehave on a date boundary, which is not something an
 * end-to-end run can wait for — so time is an argument here and the boundary is just a number.
 *
 * Run standalone: ./bin/frankenphp php-cli tests/e2e/mcp-log.test.php
 */

require dirname(__DIR__, 2) . '/app/vendor/autoload.php';

use Desktop\Mcp\RequestLog;

$failures = [];
$dir = sys_get_temp_dir() . '/adminer-mcp-log-' . getmypid();
@mkdir($dir, 0700, true);
$log = new RequestLog($dir);

/** @param mixed $actual */
$is = function (string $what, $actual, $expected) use (&$failures): void {
    if ($actual !== $expected) {
        $failures[] = "$what: expected " . json_encode($expected) . ', got ' . json_encode($actual);
    }
};

$day1 = mktime(12, 0, 0, 8, 3, 2026);
$day2 = $day1 + 86400;

// 1. The file is named for the day, which is the whole of the rotation.
$is('names today\'s file by date', basename((string) $log->path($day1)), 'mcp-' . gmdate('Y-m-d', $day1) . '.log');
$is('a later day is a different file', $log->path($day2) === $log->path($day1), false);

// 2. A request lands, tab separated, one line.
$log->append('tools/call', 'execute_query', "SELECT *\n  FROM users", $day1);
$lines = file((string) $log->path($day1), FILE_IGNORE_NEW_LINES) ?: [];
$is('one line per request', count($lines), 1);
$fields = explode("\t", $lines[0] ?? '');
$is('four fields', count($fields), 4);
$is('method logged', $fields[1] ?? '', 'tools/call');
$is('tool logged', $fields[2] ?? '', 'execute_query');
// Newlines in SQL must not become extra lines, or a single call reads as several.
$is('sql flattened to one line', $fields[3] ?? '', 'SELECT * FROM users');

// 3. Appends, never overwrites — yesterday's evidence has to survive today's writing.
$log->append('tools/list', '', '', $day1);
$is('appends', count(file((string) $log->path($day1), FILE_IGNORE_NEW_LINES) ?: []), 2);

// 4. Crossing midnight writes a new file and leaves the old one alone.
$log->append('tools/call', 'list_tables', '', $day2);
$is('yesterday untouched', count(file((string) $log->path($day1), FILE_IGNORE_NEW_LINES) ?: []), 2);
$is('today is its own file', count(file((string) $log->path($day2), FILE_IGNORE_NEW_LINES) ?: []), 1);

// 5. Old days are pruned on write; recent ones are not. 20 days back is beyond the window,
//    5 is inside it.
$old = $dir . '/mcp-' . gmdate('Y-m-d', $day2 - (20 * 86400)) . '.log';
$recent = $dir . '/mcp-' . gmdate('Y-m-d', $day2 - (5 * 86400)) . '.log';
file_put_contents($old, "old\n");
file_put_contents($recent, "recent\n");
$log->append('ping', '', '', $day2);
$is('prunes beyond the window', is_file($old), false);
$is('keeps what is inside it', is_file($recent), true);

// 6. It names tables and queries from the database, so it must not be world-readable.
$perms = substr(sprintf('%o', (int) fileperms((string) $log->path($day2))), -3);
$is('kept private', $perms, '600');

// 7. No log directory (served without the launcher) is silence, not a crash.
$none = new RequestLog(null);
$is('no directory means no path', $none->path($day1), null);
$none->append('ping', '', '', $day1); // must not throw

array_map('unlink', glob("$dir/*") ?: []);
@rmdir($dir);

if ($failures !== []) {
    echo implode("\n", $failures), "\n";
    echo 'mcp-log: ' . count($failures) . " failure(s)\n";
    exit(1);
}
echo "mcp-log ok\n";
exit(0);
