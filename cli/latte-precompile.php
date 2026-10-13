<?php
declare(strict_types=1);

// `php-cli cli/latte-precompile.php <app dir>` compiles every template of that app/ tree into its
// latte-cache/, so a packaged build never compiles one on the user's machine (~95 ms on the first
// launch). Run against the packaged copy, not the checkout: Desktop\Latte keys each compiled file
// by its path relative to the app/ it was loaded from.

require $_SERVER["argv"][1] . "/vendor/autoload.php";

echo "latte: precompiled " . Desktop\Latte::precompile() . " templates\n";
