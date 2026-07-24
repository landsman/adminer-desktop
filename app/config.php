<?php
declare(strict_types=1);
namespace Desktop;

/** Persistent application preferences — one JSON file in the durable data directory.
*
* PHP's $_SESSION does not survive a cold start here (issue #10), so a preference that must
* outlive the process — the sidebar width so far — is kept in this file instead. It lives in
* ADMINER_DESKTOP_DATA, the same durable home (Go's os.UserConfigDir) that already holds
* adminer.key, so it is backed up and survives an app upgrade rather than sitting in a temp
* dir the OS sweeps.
*
* JSON, not SQLite: this is a handful of UI preferences, so a new one is a new key with no
* schema and no migration, and the file stays readable and hand-editable. If a value's shape
* ever has to change, a "version" key plus a switch in read() is the whole migration story —
* deliberately not built until something needs it.
*
* It is read from inside adminer (head()) and written from a bare endpoint
* (settings/sidebar-width.php), so it leans on nothing but the standard library.
*/
class Config {
	/** @var string|null the file, or null when served with no durable home (e.g. `make serve`) */
	private $file;

	/** @var array<string,mixed>|null the parsed file, read once per request */
	private $cache;

	function __construct(?string $dir = null) {
		$dir = $dir ?? (getenv("ADMINER_DESKTOP_DATA") ?: null);
		$this->file = $dir !== null ? "$dir/config.json" : null;
	}

	/** @return mixed the stored value, or $default when the key is unset */
	function get(string $key, $default = null) {
		return $this->read()[$key] ?? $default;
	}

	/** @param mixed $value */
	function set(string $key, $value): void {
		if ($this->file === null) {
			return; // no durable home: nothing to write to
		}
		$dir = dirname($this->file);
		if (!is_dir($dir)) {
			@mkdir($dir, 0700, true);
		}
		$data = $this->read();
		$data[$key] = $value;
		// Write a temp file then rename over the real one: rename is atomic, so a crash
		// mid-write can never leave half a JSON object that the next read chokes on.
		$tmp = $this->file . "." . getmypid() . ".tmp";
		if (@file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT)) !== false) {
			@rename($tmp, $this->file);
			$this->cache = $data;
		}
	}

	/** @return array<string,mixed> */
	private function read(): array {
		if ($this->cache !== null) {
			return $this->cache;
		}
		$this->cache = [];
		if ($this->file !== null && is_file($this->file)) {
			$data = json_decode((string) @file_get_contents($this->file), true);
			if (is_array($data)) {
				$this->cache = $data;
			}
		}
		return $this->cache;
	}
}
