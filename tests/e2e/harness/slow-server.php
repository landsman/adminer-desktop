<?php
declare(strict_types=1);

/** A database server that answers late, and then not at all: it takes a connection, says nothing
 * for a while and hangs up. A login to it is a slow one that fails, which is what the activity
 * indicator and the error in the modal are for, without waiting out a real host's TCP timeout
 * (75 seconds on macOS). e2e_slow_server() starts it for the scenarios; to see the same in the app:
 *
 *     ./bin/frankenphp php-cli tests/e2e/harness/slow-server.php 55999 3
 *
 * then log in to 127.0.0.1:55999. postgres asks twice, with SSL and without, so a login takes
 * twice the seconds given.
 */

$port = (int) ($argv[1] ?? 55999);
$seconds = (float) ($argv[2] ?? 1);
$server = stream_socket_server("tcp://127.0.0.1:$port", $errno, $error);
if (!$server) {
	fwrite(STDERR, "$error\n");
	exit(1);
}
echo "listening on 127.0.0.1:$port\n";
while ($client = @stream_socket_accept($server, -1)) {
	usleep((int) ($seconds * 1_000_000));
	fclose($client);
}
