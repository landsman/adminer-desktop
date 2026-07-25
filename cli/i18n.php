<?php
declare(strict_types=1);

// Thin CLI over Desktop\I18n\*: `php-cli cli/i18n.php` regenerates the macOS .strings and the
// launcher C table from the native language files; `... check` prints a coverage report for every
// domain (native + plugin) and exits non-zero on a gap, for CI. The classes live in app/src/I18n
// and resolve through the composer autoloader, the same way every other entry point loads a
// Desktop\ class. Only the native domain is generated; the plugin domain is served at runtime by
// AdminerDesktop, so here it is coverage-checked only.

use Desktop\I18n\Catalog;
use Desktop\I18n\Generator;

require dirname(__DIR__) . "/app/vendor/autoload.php";

$root = dirname(__DIR__);

if (($argv[1] ?? "") === "check") {
	$ok = true;
	foreach (["native", "plugin"] as $domain) {
		$catalog = Catalog::load($domain);
		echo (new Generator($catalog))->report($domain), "\n";
		$ok = $ok && $catalog->complete();
	}
	exit($ok ? 0 : 1);
}

$catalog = Catalog::load("native");
(new Generator($catalog))->generate($root);
echo "i18n: wrote " . count($catalog->all()) . " .strings + launcher/i18n_gen.h\n";
