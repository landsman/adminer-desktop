<?php
declare(strict_types=1);

// Thin CLI over Desktop\I18n\*: `php-cli cli/i18n.php` regenerates the macOS .strings and the
// launcher C table from the per-language files; `... check` prints the coverage report and exits
// non-zero on a gap, for CI. The classes live in app/src/I18n and resolve through the composer
// autoloader, the same way every other entry point in the app loads a Desktop\ class.

use Desktop\I18n\Catalog;
use Desktop\I18n\Generator;

require dirname(__DIR__) . "/app/vendor/autoload.php";

$root = dirname(__DIR__);
$catalog = Catalog::load();
$generator = new Generator($catalog);

if (($argv[1] ?? "") === "check") {
	echo $generator->report();
	exit($catalog->complete() ? 0 : 1);
}

$generator->generate($root);
echo "i18n: wrote " . count($catalog->all()) . " .strings + launcher/i18n_gen.h\n";
