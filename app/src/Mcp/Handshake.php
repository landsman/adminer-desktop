<?php
declare(strict_types=1);

namespace Desktop\Mcp;

use Desktop\Env;
use Desktop\Os;

/** Where the MCP client learns how to reach this window.
*
* The launcher binds fresh random ports every start (launcher/main.go, freePort), so no URL a
* user could paste into an MCP config would survive a restart. While a connected request is
* being served, the plugin drops the gate's URL for this window's connection here, with the
* gate's token; mcp.php reads them back per message.
*
* Never a cookie. The session's cookies decrypt the database passwords adminer keeps in it, and
* this file is readable by the agent's own OS user — 0600 keeps out other accounts, not the
* agent. The launcher's gate holds them in memory and adds them on the way through
* (launcher/mcpgate); the token here opens the MCP endpoint and nothing else.
*/
class Handshake {
	/** Same 0700 directory as adminer.key and settings.json. */
	private ?string $file;

	function __construct(?string $dir = null) {
		$dir = $dir ?? (Env::DataDir->get() ?: self::platformDir());
		$this->file = $dir !== null ? "$dir/mcp.json" : null;
	}

	/** Where the data directory is when nobody told us — mirroring Go's os.UserConfigDir().
	*
	* Every other reader of the data dir runs inside the server, where the launcher passes
	* ADMINER_DESKTOP_DATA in, and guessing there would be wrong: an unset variable means
	* `make serve`, which is meant to persist nothing. The bridge is the exception. An agent
	* spawns it as a bare child process with no environment of ours, so without this it finds no
	* handshake and reports the app as not running while it is open in front of you.
	*
	* Kept here rather than on Env for that reason — it is right for this one caller and wrong
	* for the others. It must stay in step with launcher/prefs.go dataDir().
	*/
	private static function platformDir(): ?string {
		$base = Os::current()->configDir();
		return $base !== null ? "$base/Adminer Desktop" : null;
	}

	/** @return string|null the path, or null when the app has no durable home (`make serve`) */
	function path(): ?string {
		return $this->file;
	}

	/** Record how to reach this window.
	*
	* Called on every connected request while the feature is on, because the window moves between
	* connections and the ports change per launch — the cost is one small write against a file the
	* OS has cached, and the alternative is a handshake that goes stale without anything noticing.
	*
	* @param string $target which connection this points at, for the settings panel to show —
	*                       the agent follows wherever the window last was, and that is worth
	*                       being able to see rather than infer
	* @param string $socket the gate's unix socket, which the bridge posts to instead of the url's
	*                       port: a port a quit app leaves behind can be bound by anyone
	*/
	function write(string $url, string $token, string $target = '', string $socket = ''): void {
		if ($this->file === null) {
			return;
		}
		$dir = dirname($this->file);
		if (!is_dir($dir)) {
			@mkdir($dir, 0700, true);
		}
		$payload = json_encode(['url' => $url, 'token' => $token, 'target' => $target, 'socket' => $socket], JSON_PRETTY_PRINT);
		if ($payload === false) {
			return;
		}
		// Same temp-then-rename as UserSettings: the shim may read while we write, and a
		// half-written handshake reads as a corrupt one rather than an old one. A random name, not
		// the pid: every frankenphp thread shares one, and two writes would share the temp file.
		$tmp = $this->file . '.' . bin2hex(random_bytes(6)) . '.tmp';
		if (@file_put_contents($tmp, $payload) === false) {
			return;
		}
		@chmod($tmp, 0600); // the gate's token: no other account gets to use it
		// Windows refuses to replace a file another process has open, and the bridge opens this
		// one on every message; a moment later it has let go.
		for ($try = 0; !@rename($tmp, $this->file); $try++) {
			if ($try === 5) {
				@unlink($tmp);
				return;
			}
			usleep(20_000);
		}
	}

	/** What mcp.php reads back. A handshake from before the gate, still carrying cookies, has no
	* token and reads as none: the next page the window loads writes the new one. An empty url or
	* token reads as none too, as it does in the Go bridge (launcher/mcpbridge): the gate would
	* refuse it, and "expired" would send the agent after the wrong cause.
	* @return array{url:string,token:string,target:string,socket:string}|null null when absent or unusable
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
		if (!is_array($data) || !is_string($data['url'] ?? null) || !is_string($data['token'] ?? null) || $data['url'] === '' || $data['token'] === '') {
			return null;
		}
		// Only ever the gate, on this machine: a url anywhere else would hand the token and the
		// agent's SQL to whoever is there, and the answer back to the agent as ours.
		if (!self::isLoopback($data['url'])) {
			return null;
		}
		$target = isset($data['target']) && is_string($data['target']) ? $data['target'] : '';
		$socket = isset($data['socket']) && is_string($data['socket']) ? $data['socket'] : '';
		return ['url' => $data['url'], 'token' => $data['token'], 'target' => $target, 'socket' => $socket];
	}

	/** http on 127.0.0.1, which is all the launcher's gate ever listens on. */
	static function isLoopback(string $url): bool {
		$parts = parse_url($url);
		return is_array($parts) && ($parts['scheme'] ?? '') === 'http' && ($parts['host'] ?? '') === '127.0.0.1';
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
