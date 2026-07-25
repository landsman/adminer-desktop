<?php
declare(strict_types=1);

/** Does the i18n generator emit correct .strings and a correct C table from the language files,
 * and report coverage correctly?
 *
 * The output half generates into a throwaway dir and asserts the shape the native shells depend
 * on: the Catalog loads exactly the locales the enum declares, every base key reaches each
 * locale's .strings, translations carry through, multiline values escape to one line, macOS %@
 * becomes printf %s for C, and English rows are omitted (they are the fallback). The coverage
 * half feeds the Catalog an in-memory gap so the missing/report/return-code path is exercised too.
 * No database, no browser, so `make qa` runs it via frankenphp.
 */

use Desktop\I18n\Catalog;
use Desktop\I18n\Generator;
use Desktop\I18n\Locale;
use Tester\Assert;

require dirname(__DIR__) . "/bootstrap.php";

$root = dirname(__DIR__, 2) . "/.cache/i18n-test";
array_map('unlink', glob("$root/lproj/*.lproj/Localizable.strings") ?: []);
(new Generator(Catalog::load()))->generate($root);

$catalog = Catalog::load();
$base = $catalog->base();
$cs = (string) file_get_contents("$root/lproj/cs.lproj/Localizable.strings");
$en = (string) file_get_contents("$root/lproj/en.lproj/Localizable.strings");
$h = (string) file_get_contents("$root/launcher/i18n_gen.h");

// The Catalog loads exactly the locales the enum declares, in that order.
Assert::same(array_map(fn (Locale $l) => $l->value, Locale::cases()), array_keys($catalog->all()));

// The shipped locales are fully translated.
Assert::true($catalog->complete());
Assert::same([], $catalog->missing());

// Every base key reaches each locale's .strings as an NSLocalizedString key.
foreach (array_keys($base) as $key) {
	Assert::contains('"' . $key . '" = ', $en, "en is missing $key");
	Assert::contains('"' . $key . '" = ', $cs, "cs is missing $key");
}

// Translations carry through; English degrades a key to itself.
Assert::contains('"Save Export" = "Uložit export";', $cs);
Assert::contains('"Save Export" = "Save Export";', $en);

// Multiline values escape to one physical line, so every entry stays a valid single statement.
Assert::contains('\nApache-2.0', $cs);
foreach (explode("\n", trim($cs)) as $line) {
	$line = trim($line);
	if ($line !== "" && !str_starts_with($line, "/*")) {
		Assert::true(str_ends_with($line, ";"), "unterminated .strings line: $line");
	}
}

// C table: placeholders rewritten to printf, English omitted (it is the fallback), identical
// translations omitted, and adTr present.
Assert::notContains('%@', $h);
Assert::contains('{"cs", "Saved %s", "Uloženo %s"},', $h);
Assert::notContains('{"en",', $h);
Assert::notContains('"OK", "OK"', $h);
Assert::contains('static const char *adTr(const char *en)', $h);

// Coverage on injected data: a fully translated catalog reports complete...
$complete = new Catalog(['en' => ['A' => 'A', 'B' => 'B'], 'cs' => ['A' => 'Á', 'B' => ' B']]);
Assert::true($complete->complete());
Assert::same([], $complete->missing());
Assert::contains('All translations complete.', (new Generator($complete))->report());
Assert::contains('100%', (new Generator($complete))->report());

// ...and a catalog with a gap reports the missing key, the percentage, and is not complete.
$gap = new Catalog(['en' => ['A' => 'A', 'B' => 'B'], 'cs' => ['A' => 'Á']]);
Assert::false($gap->complete());
Assert::same(['cs' => ['B']], $gap->missing());
$report = (new Generator($gap))->report();
Assert::contains('Missing in cs', $report);
Assert::contains('- B', $report);
Assert::contains('50%', $report);
