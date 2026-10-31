<?php
declare(strict_types=1);

namespace Desktop\Cli;

use Desktop\I18n\Catalog;
use Desktop\I18n\Domain;
use Desktop\I18n\Generator;

/** Regenerates the macOS .strings and the launcher's C table from the native language files, and
* gates every domain's coverage. Only the native domain is generated: the plugin domain is served
* at runtime by AdminerDesktop, so it is only checked.
*/
class I18n {
	static function run(): void {
		$catalog = Catalog::load(Domain::Native);
		(new Generator($catalog))->generate(dirname(__DIR__, 2));
		echo "i18n: wrote " . count($catalog->all()) . " .strings + launcher/i18n_gen.h\n";
	}

	/** Exits non-zero on a gap, for CI. */
	static function check(): void {
		$ok = true;
		foreach (Domain::cases() as $domain) {
			$ok = self::passes($domain->value, Catalog::load($domain)) && $ok;
		}
		exit($ok ? 0 : 1);
	}

	/** Prints the domain's report, and says whether it is complete with every ID well-formed. */
	static function passes(string $label, Catalog $catalog): bool {
		echo (new Generator($catalog))->report($label), "\n";
		$malformed = $catalog->malformedIds();
		if ($malformed !== []) {
			echo "Malformed IDs in $label: '" . implode("', '", $malformed) . "'\n\n";
		}
		return $catalog->complete() && $malformed === [];
	}
}
