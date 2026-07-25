<?php
declare(strict_types=1);

// Thin CLI over Desktop\I18n\*: `php-cli cli/i18n.php` regenerates the macOS .strings and the
// launcher C table from the per-language files; `... check` prints the coverage report and exits
// non-zero on a gap, for CI. The classes live in app/src/I18n and are required directly rather than
// via composer, so this stays a plain build step with no vendor dependency and a new class in the
// folder needs no list here.

use Desktop\I18n\Catalog;
use Desktop\I18n\Generator;

$root = dirname(__DIR__);
foreach (glob($root . "/app/src/I18n/*.php") ?: [] as $file) {
	require_once $file;
}

$catalog = Catalog::load();
$generator = new Generator($catalog);

if (($argv[1] ?? "") === "check") {
	echo $generator->report();
	exit($catalog->complete() ? 0 : 1);
}

$generator->generate($root);
echo "i18n: wrote " . count($catalog->all()) . " .strings + launcher/i18n_gen.h\n";
