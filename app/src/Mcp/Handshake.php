<?php
declare(strict_types=1);

namespace Desktop\Mcp;

use Desktop\Env;

/** Where the MCP client learns how to reach this window, and as whom.
*
* The launcher binds a fresh random port every start (launcher/main.go, freePort), so no URL a
* user could paste into an MCP config would survive a restart. And an agent talking to us over
* stdio has no browser and no cookie jar, so it cannot be logged in on its own account.
*
* Both problems are one file. While a connected request is being served, the plugin drops the
* base URL and this session's cookies here; mcp-stdio.php reads them back and replays them, so
* the agent queries exactly the database this window is logged into — for as long as that stays
* true, and no longer.
*
* Deliberately no credentials and no bearer token of our own. Adminer already keeps the
* connection behind its session, and a token stored beside the cookies in the same 0600 file
* would guard nothing that reading the file does not already give away. The cookies are the
* credential; the file's permissions are what protects them.
*/
class Handshake {
	/** Same 0700 directory as adminer.key and settings.json. */
	private ?string $file;

	function __construct(?string $dir = null) {
		$dir = $dir ?? (Env::DataDir->get() ?: null);
		$this->file = $dir !== null ? "$dir/mcp.json" : null;
	}

	/** @return string|null the path, or null when the app has no durable home (`make serve`) */
	function path(): ?string {
		return $this->file;
	}

	/** Record how to reach this window as this session.
	*
	* Called on every connected request while the feature is on, because the cookies rotate and
	* the port changes per launch — the cost is one small write against a file the OS has
	* cached, and the alternative is a handshake that goes stale without anything noticing.
	*
	* @param array<string,string> $cookies
	*/
	function write(string $url, array $cookies): void {
		if ($this->file === null) {
			return;
		}
		$dir = dirname($this->file);
		if (!is_dir($dir)) {
			@mkdir($dir, 0700, true);
		}
		$payload = json_encode(['url' => $url, 'cookies' => $cookies], JSON_PRETTY_PRINT);
		if ($payload === false) {
			return;
		}
		// Same temp-then-rename as UserSettings: the shim may read while we write, and a
		// half-written handshake reads as a corrupt one rather than an old one.
		$tmp = $this->file . '.' . getmypid() . '.tmp';
		if (@file_put_contents($tmp, $payload) !== false) {
			@chmod($tmp, 0600); // session cookies: readable by this account only
			@rename($tmp, $this->file);
		}
	}

	/** What mcp-stdio.php reads back.
	* @return array{url:string,cookies:array<string,string>}|null null when absent or unusable
	*/
	function read(): ?array {
		if ($this->file === null || !is_file($this->file)) {
			return null;
		}
		$raw = @file_get_contents($this->file);
		if ($raw === false) {
			return null;
		}
		$data = json_decode($raw, true);
		if (!is_array($data) || !isset($data['url']) || !is_string($data['url']) || !isset($data['cookies']) || !is_array($data['cookies'])) {
			return null;
		}
		/** @var array<string,string> $cookies */
		$cookies = array_filter($data['cookies'], 'is_string');
		return ['url' => $data['url'], 'cookies' => $cookies];
	}

	/** Drop the handshake — nothing may reach the database through it after this.
	*
	* What "turned it off" has to mean: the setting alone would leave a file on disk that still
	* names a live session.
	*/
	function clear(): void {
		if ($this->file !== null) {
			@unlink($this->file);
		}
	}
}
