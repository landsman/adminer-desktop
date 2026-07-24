<?php
declare(strict_types=1);
namespace Desktop;

/** The environment variables the launcher sets and the app reads — the whole contract in one
* list, so a getenv() call cannot quietly mistype a name that only the Go side would know is
* wrong.
*
* The launcher (main.go and friends) sets these by the same literal names; this is the PHP end
* of that contract. get() wraps getenv so a call site reads Env::Debug->get() and never repeats
* the string.
*/
enum Env: string {
	/** Durable data dir (Go's os.UserConfigDir); holds adminer.key and settings.json. */
	case Data = 'ADMINER_DESKTOP_DATA';
	/** Set under -debug: turns on Tracy and the web inspector's own affordances. */
	case Debug = 'ADMINER_DESKTOP_DEBUG';
	/** `make demo` only: the throwaway connection demo-login.js fills and submits. */
	case Demo = 'ADMINER_DESKTOP_DEMO';

	/** The value, or false when unset — getenv's own contract. */
	function get(): string|false {
		return getenv($this->value);
	}
}
