<?php
declare(strict_types=1);
namespace Desktop;

/** A colour scheme side: the light half or the dark half.
*
* adminer's css() hook is handed one stylesheet per side, the design gallery has a light and
* a dark column, and the session remembers a design per side — "light or dark" was a bare
* string threaded through all of it. This makes it a type instead, so a typo is a parse error
* rather than a silently mismatched side.
*
* Backed by "light"/"dark" because those exact strings still have to cross real boundaries:
* the session keys (design_light), the POST field names, and the scheme tag design.inc.php
* reads off cssMap(). So ->value is what leaves this type, and Mode::from()/tryFrom() is how a
* string from one of those boundaries comes back in.
*/
enum Mode: string {
	case Light = 'light';
	case Dark = 'dark';
}
