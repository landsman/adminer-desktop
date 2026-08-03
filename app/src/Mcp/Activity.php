<?php
declare(strict_types=1);

namespace Desktop\Mcp;

use Desktop\Env;
use Desktop\Os;

/** When an agent last asked this window something.
*
* There is no connection to observe. MCP over stdio is one HTTP POST per message, so "an agent
* is connected" is not a state that exists — what does exist, and is what a person actually
* wants to know, is whether anything has used it and how recently.
*
* That makes this a small safety feature rather than a nicety: the panel saying a query ran
* thirty seconds ago, when you did not ask for one, is how you would notice.
*
* Its own file rather than a field in the handshake, because the lifetimes differ: the
* handshake is retracted the moment the feature is switched off, and the record of it having
* been used should survive that.
*/
class Activity {
	private ?string $file;

	function __construct(?string $dir = null) {
		$dir = $dir ?? (Env::DataDir->get() ?: self::platformDir());
		$this->file = $dir !== null ? "$dir/mcp-activity.json" : null;
	}

	private static function platformDir(): ?string {
		$base = Os::current()->configDir();
		return $base !== null ? "$base/Adminer Desktop" : null;
	}

	/** Note that an agent asked for something.
	*
	* Deliberately not a log: one line, overwritten. A per-request history of what an agent read
	* would be a second copy of your query patterns sitting on disk, which is a worse trade than
	* the question it answers.
	*/
	function record(string $method, int $now): void {
		if ($this->file === null) {
			return;
		}
		$payload = json_encode(['at' => $now, 'method' => $method]);
		if ($payload !== false) {
			@file_put_contents($this->file, $payload);
			@chmod($this->file, 0600);
		}
	}

	/** @return array{at:int,method:string}|null */
	function last(): ?array {
		if ($this->file === null || !is_file($this->file)) {
			return null;
		}
		$raw = @file_get_contents($this->file);
		if ($raw === false) {
			return null;
		}
		$data = json_decode($raw, true);
		if (!is_array($data) || !isset($data['at']) || !is_int($data['at'])) {
			return null;
		}
		$method = isset($data['method']) && is_string($data['method']) ? $data['method'] : '';
		return ['at' => $data['at'], 'method' => $method];
	}
}
