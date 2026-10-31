<?php
declare(strict_types=1);

/** Does the coverage gate fail on what it exists to catch? A gate that passes everything looks
 * exactly like one that found nothing, and CI runs it to decide.
 */

use Desktop\Cli\I18n;
use Desktop\I18n\Catalog;
use Tester\Assert;

require dirname(__DIR__) . "/bootstrap.php";

$passes = function (array $byLocale): array {
	ob_start();
	$passed = I18n::passes("test", new Catalog($byLocale));
	return [$passed, (string) ob_get_clean()];
};

[$passed] = $passes(['en' => ['menu.open' => 'Open'], 'cs' => ['menu.open' => 'Otevřít']]);
Assert::true($passed);

[$passed, $out] = $passes(['en' => ['menu.open' => 'Open'], 'cs' => []]);
Assert::false($passed);
Assert::contains('menu.open', $out);

[$passed, $out] = $passes(['en' => ['Bad Id' => 'Open'], 'cs' => ['Bad Id' => 'Otevřít']]);
Assert::false($passed);
Assert::contains("Malformed IDs in test: 'Bad Id'", $out);
