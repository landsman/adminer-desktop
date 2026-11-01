<?php
declare(strict_types=1);

namespace Desktop\Mcp;

/** Tell the launcher's gate which session to lend (launcher/mcpgate).
*
* The cookies go only to whoever proves it holds the key. On Windows the gate is a loopback port,
* which another account can bind once the launcher has died and frankenphp has not.
*/
final class GateReport {
	/** @return bool whether the gate proved itself and was told */
	static function send(string $address, string $key, string $cookie, string $endpoint): bool {
		$gate = @stream_socket_client($address, $errno, $error, 1);
		if ($gate === false) {
			return false;
		}
		stream_set_timeout($gate, 1); // an impostor that never answers must not hold up the page
		$challenge = bin2hex(random_bytes(32));
		@fwrite($gate, "$challenge\n");
		$proof = fgets($gate);
		$proven = is_string($proof) && hash_equals(hash_hmac('sha256', $challenge, $key), rtrim($proof));
		if ($proven) {
			@fwrite($gate, json_encode(['key' => $key, 'cookie' => $cookie, 'endpoint' => $endpoint]) . "\n");
		}
		fclose($gate);
		return $proven;
	}
}
