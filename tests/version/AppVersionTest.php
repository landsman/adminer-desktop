<?php
declare(strict_types=1);

/** Does the login page's version link read the launcher's `git describe` right? A tag goes to its
 * release, a build past a tag to its commit, and anything else — "dev", a bare SHA from a clone
 * with no tags — to the repo, with -dirty never part of a link. No launcher needed.
 */

use Desktop\AppVersion;
use Tester\Assert;

require dirname(__DIR__) . "/bootstrap.php";

$repo = AppVersion::REPO;
$cases = [
	'beta-v0.7.2' => ['beta-v0.7.2', "$repo/releases/tag/beta-v0.7.2"],
	'beta-v0.7.2-dirty' => ['beta-v0.7.2', "$repo/releases/tag/beta-v0.7.2"],
	'beta-v0.7.2-15-gaa1715f-dirty' => ['beta-v0.7.2+aa1715f', "$repo/commit/aa1715f"],
	'aa1715f' => ['aa1715f', $repo],
	'dev' => ['dev', $repo],
];
foreach ($cases as $describe => [$label, $url]) {
	$version = new AppVersion($describe);
	Assert::same($label, $version->label(), $describe);
	Assert::same($url, $version->url(), $describe);
}
echo "app version ok\n";
