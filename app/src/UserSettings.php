<?php
declare(strict_types=1);

namespace Desktop;

/** The user's own preferences — what they chose, not what we configure for them — kept in one
* JSON file in the durable data directory.
*
* PHP's $_SESSION does not survive a cold start here (issue #10), so a preference that must
* outlive the process — the sidebar width so far — is kept in this file instead. It lives in
* ADMINER_DESKTOP_DATA, the same durable home (Go's os.UserConfigDir) that already holds
* adminer.key, so it is backed up and survives an app upgrade rather than sitting in a temp
* dir the OS sweeps.
*
* JSON, not SQLite: this is a handful of UI preferences, so a new one is a new SettingKey with
* no schema and no migration, and the file stays readable and hand-editable. If a value's
* shape ever has to change, a "version" key plus a switch in read() is the whole migration
* story — deliberately not built until something needs it.
*
* Keys are the SettingKey enum, not free strings, so a typo is a type error and the whole set
* of what can be stored is one list. It is read from inside adminer (head()) and written from a
* the api (src/Api/ResizePreference.php), so it leans on nothing but the standard library.
*/
class UserSettings {
	/** the file, or null when served with no durable home (e.g. `make serve`) */
	private ?string $file;

	/** the parsed file, read once per request; null until first read
	* @var array<string,mixed>|null */
	private ?array $cache = null;

	function __construct(?string $dir = null) {
		$dir = $dir ?? (Env::DataDir->get() ?: null);
		$this->file = $dir !== null ? "$dir/settings.json" : null;
	}

	/** @return mixed the stored value, or $default when the key is unset */
	function get(SettingKey $key, mixed $default = null) {
		return $this->read()[$key->value] ?? $default;
	}

	function set(SettingKey $key, mixed $value): void {
		if ($this->file === null) {
			return; // no durable home: nothing to write to
		}
		$dir = dirname($this->file);
		if (!is_dir($dir)) {
			@mkdir($dir, 0700, true);
		}
		$data = $this->read();
		$data[$key->value] = $value;
		// Write a temp file then rename over the real one: rename is atomic, so a crash
		// mid-write can never leave half a JSON object that the next read chokes on.
		$tmp = $this->file . "." . getmypid() . ".tmp";
		if (@file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT)) !== false) {
			@rename($tmp, $this->file);
			$this->cache = $data;
		}
	}

	/** Throw the whole file away: every preference back to what a fresh install has.
	*
	* Deleted rather than rewritten with the defaults, because there is no list of defaults to
	* write — each one lives as the fallback in the code that reads its key, so an unset key and
	* a key set to its default are the same thing, and only one of them can go stale. The file
	* comes back on the next preference the user changes.
	*
	* This is our file only. Adminer's own state — the language, the saved servers, the
	* permanent-login key — is adminer's, kept elsewhere, and is not a preference this dialog
	* ever set.
	*/
	function reset(): void {
		if ($this->file !== null) {
			@unlink($this->file);
		}
		$this->cache = [];
	}

	/** Everything stored, for inspection (the Tracy panel prints it under -debug).
	* @return array<string,mixed> */
	function all(): array {
		return $this->read();
	}

	/** The backing file, or null when there is no durable home to write to. */
	function path(): ?string {
		return $this->file;
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
