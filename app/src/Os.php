<?php
declare(strict_types=1);

namespace Desktop;

/** Which machine the app is running on, and the handful of things that differ because of it.
*
* PHP_OS_FAMILY is the real answer here rather than anything from the request: frankenphp runs
* on the user's machine, so the server's OS is the user's OS. That is why this can be a plain
* enum and not something negotiated per request.
*
* It exists because three places had grown the same match on PHP_OS_FAMILY — the body class, the
* data directory the MCP bridge falls back to, and the registration command the settings dialog
* prints — and a fourth was about to. Adding a case here is now the whole of adding a platform.
*/
enum Os: string {
	case Mac = 'mac';
	case Windows = 'windows';
	case Linux = 'linux';

	/** Linux is the default for anything unrecognised: it is the least surprising place for a
	* BSD or an unknown family to land, and every branch below has a sane answer for it. */
	static function current(): self {
		return match (PHP_OS_FAMILY) {
			'Darwin' => self::Mac,
			'Windows' => self::Windows,
			default => self::Linux,
		};
	}

	/** The class on <body> the theme keys off (AdminerDesktop::bodyClass). */
	function bodyClass(): string {
		return 'os-' . $this->value;
	}

	/** The name a person recognises, for the settings UI. */
	function label(): string {
		return match ($this) {
			self::Mac => 'macOS',
			self::Windows => 'Windows',
			self::Linux => 'Linux',
		};
	}

	/** This platform's config directory, mirroring Go's os.UserConfigDir — the parent of the
	* app's own data directory.
	*
	* Null when the environment does not say where home is, which is not a case worth inventing
	* a path for: the caller then has nothing to offer and should say so.
	*/
	function configDir(): ?string {
		$base = match ($this) {
			self::Windows => getenv('AppData'),
			self::Mac => ($home = getenv('HOME')) !== false ? "$home/Library/Application Support" : false,
			self::Linux => self::xdgConfigHome(),
		};
		return is_string($base) && $base !== '' ? $base : null;
	}

	/** @return string|false */
	private static function xdgConfigHome() {
		$xdg = getenv('XDG_CONFIG_HOME');
		if (is_string($xdg) && $xdg !== '') {
			return $xdg;
		}
		$home = getenv('HOME');
		return $home !== false ? "$home/.config" : false;
	}
}
