<?php
declare(strict_types=1);

namespace Desktop\Cli\Command;

use Desktop\Latte;

/** Compiles every template of the app/ tree this file sits in into its latte-cache/, so a packaged
* build never compiles one on the user's machine (~95 ms on the first launch). Run from the packaged
* copy, not the checkout: Desktop\Latte keys each compiled file by its path relative to the app/ it
* was loaded from.
*/
class LattePrecompile {
	static function run(): void {
		echo "latte: precompiled " . Latte::precompile() . " templates\n";
	}
}
