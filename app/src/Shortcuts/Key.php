<?php
declare(strict_types=1);

namespace Desktop\Shortcuts;

use Desktop\Os;

/** The keys a shortcut is written with that are not a plain character, each named the way its
* platform prints it: ⌘ and ↩ on a mac, Ctrl and Enter elsewhere.
*
* Mod is the one that differs in meaning, not only in name: ⌘ on macOS, Ctrl on Windows and Linux,
* which is what adminer's own isCtrl() accepts and what every shortcut here listens for.
*/
enum Key {
	case Ctrl;
	case Alt;
	case Shift;
	case Mod;
	case Enter;
	case Escape;
	case Up;
	case Down;

	/** The order each platform writes modifiers in: ⌃⌥⇧⌘ is Apple's, Ctrl+Alt+Shift Microsoft's.
	* A combination is sorted by this, so the list can be written in any order.
	*/
	function order(Os $os): int {
		return match ($this) {
			self::Ctrl => 0,
			self::Mod => $os === Os::Mac ? 3 : 0,
			self::Alt => 1,
			self::Shift => 2,
			default => 4,
		};
	}

	function label(Os $os): string {
		$mac = $os === Os::Mac;
		return match ($this) {
			self::Ctrl => $mac ? '⌃' : 'Ctrl',
			self::Alt => $mac ? '⌥' : 'Alt',
			self::Shift => $mac ? '⇧' : 'Shift',
			self::Mod => $mac ? '⌘' : 'Ctrl',
			self::Enter => $mac ? '↩' : 'Enter',
			self::Escape => 'Esc',
			self::Up => '↑',
			self::Down => '↓',
		};
	}
}
