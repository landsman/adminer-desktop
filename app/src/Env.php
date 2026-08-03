<?php
declare(strict_types=1);

namespace Desktop;

/** The environment variables the launcher sets and the app reads — the whole contract in one
* list, so a read cannot quietly mistype a name only the Go side would know is wrong. The
* launcher (main.go and friends) sets these by the same literal names; this is the PHP end.
*
* Read one with ->get(): `$dir = Env::DataDir->get()`. The case names say what each holds.
*/
enum Env: string {
	/** Durable data directory (Go's os.UserConfigDir); holds adminer.key and settings.json. */
	case DataDir = 'ADMINER_DESKTOP_DATA';
	/** Set under -debug: turns on Tracy and the web inspector's own affordances. */
	case Debug = 'ADMINER_DESKTOP_DEBUG';
	/** `make demo` only: the throwaway connection demo-login.js fills and submits. */
	case Demo = 'ADMINER_DESKTOP_DEMO';
	/** The launcher's own path (Go's os.Executable), so the MCP panel can print the exact
	* registration command for this install. Unset when app/ is served without the launcher. */
	case Exe = 'ADMINER_DESKTOP_EXE';

	/** The value of this environment variable, or false when it is not set (getenv's contract). */
	function get(): string|false {
		return getenv($this->value);
	}
}
