<?php
declare(strict_types=1);

namespace Desktop\I18n;

/** A locale Adminer Desktop ships its own strings in.
*
* A fixed set, so an enum rather than free strings: the backed value is the code that must match
* across every boundary — the i18n/native/<value>.php language file, the generated <value>.lproj
* bundle macOS reads, and the $LANG prefix the launcher matches. Adding a language is adding a
* case here and its language file; the generator and `make i18n-check` follow from this list.
*
* English is the base every other locale is translated against. This covers our own strings only
* (the native shell, and in time the PHP plugin UI); adminer's own interface is localised upstream
* and picks its language independently.
*/
enum Locale: string {
	case En = 'en';
	case Cs = 'cs';
	case De = 'de';
	case Pl = 'pl';
	case Ro = 'ro';
	case Sk = 'sk';
}
