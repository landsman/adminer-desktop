<?php
declare(strict_types=1);

namespace Desktop\I18n;

/** A group of strings sharing one consumption path, and the directory of per-language files that
* holds them under app/src/I18n/.
*
* "native" is the launcher shell — the macOS menu/dialogs and the Linux download UI — which
* Generator compiles into .strings and a C table. "plugin" is the PHP UI, fed to Adminer's
* Plugin::lang() as AdminerDesktop::$translations at runtime. A fixed set, so an enum rather than
* a bare string: Catalog::load() takes one of these, not a directory name a typo could invent.
*/
enum Domain: string {
	case Native = 'native';
	case Plugin = 'plugin';
}
