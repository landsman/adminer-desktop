<?php
// Plugins that need constructor arguments must be instantiated here; everything the
// user drops into adminer-plugins/ is picked up automatically (and auto-enabled) by
// adminer itself — see include/plugins.inc.php:17.

require_once __DIR__ . "/plugins-available/designs.php";

$designs = array();
foreach (glob(__DIR__ . "/designs/*/*.css") as $filename) {
	$name = basename(dirname($filename));
	// A design dir holds adminer.css and optionally adminer-dark.css; without this both
	// would land in the dropdown under the same label.
	if (preg_match('~-dark~', basename($filename))) {
		$name .= " (dark)";
	}
	$designs["designs/" . basename(dirname($filename)) . "/" . basename($filename)] = $name;
}
ksort($designs);

return array(
	new AdminerDesigns($designs),
);
