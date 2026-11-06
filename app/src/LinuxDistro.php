<?php
declare(strict_types=1);

namespace Desktop;

/** The Linux flavours with a look of their own. A flavour is a distribution and its desktop together,
* because the distribution alone cannot tell them apart: Kubuntu reports the ID of Ubuntu, and only
* its desktop differs. Anything else has no case, and keeps the plain Linux look.
*/
enum LinuxDistro: string {
	case Omarchy = 'omarchy';
	case Ubuntu = 'ubuntu';
	case Kubuntu = 'kubuntu';
	case Mint = 'mint';

	/** The flavour the UI dresses as: this machine's, unless -debug asks for another OS or flavour. */
	static function look(): ?self {
		$simulated = Env::Debug->get() ? (string) Env::SimulateOs->get() : '';
		if ($simulated !== '') {
			return self::tryFrom($simulated);
		}
		return Os::current() === Os::Linux ? self::current() : null;
	}

	/** This machine's flavour. */
	static function current(): ?self {
		$osRelease = @file_get_contents('/etc/os-release') ?: @file_get_contents('/usr/lib/os-release') ?: '';
		$home = getenv('HOME');
		// omarchy-theme-set keeps the current theme under this state folder and the user's under .config.
		$omarchy = $home !== false && (is_dir("$home/.local/state/omarchy") || is_dir("$home/.config/omarchy"));
		return self::detect($osRelease, (string) Env::CurrentDesktop->get(), $omarchy);
	}

	/** Omarchy first, as it installs onto Arch and leaves the ID alone; only in its Hyprland session,
	* since another session on that machine does not look like it.
	* @param string $osRelease the contents of os-release
	* @param string $desktop XDG_CURRENT_DESKTOP, a colon-separated list such as "ubuntu:GNOME"
	*/
	static function detect(string $osRelease, string $desktop, bool $omarchyInstalled): ?self {
		$desktops = explode(':', strtolower($desktop));
		if ($omarchyInstalled && in_array('hyprland', $desktops, true)) {
			return self::Omarchy;
		}
		preg_match('~^ID="?([^"\n]+)~m', $osRelease, $match);
		return match ($match[1] ?? '') {
			'linuxmint' => self::Mint,
			// kubuntu too: no source shows what Kubuntu's os-release says, and KDE decides either way.
			'ubuntu', 'kubuntu' => match (true) {
				in_array('kde', $desktops, true) => self::Kubuntu,
				in_array('gnome', $desktops, true) => self::Ubuntu,
				default => null,
			},
			default => null,
		};
	}

	/** The class on <body>, and the name of the stylesheet Theme::cssMap() loads if there is one. */
	function bodyClass(): string {
		return $this->value . '-linux';
	}
}
