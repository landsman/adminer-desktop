<?php
// Plugins that need constructor arguments must be instantiated here; everything the
// user drops into adminer-plugins/ is picked up automatically (and auto-enabled) by
// adminer itself — see include/plugins.inc.php:17.

// Ours, always on — it is app behaviour, not an optional plugin, so it lives here
// rather than in adminer-plugins/ which is the user's own enabled set.
require_once __DIR__ . "/desktop.php";

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

// The designs plugin only applies a switch from afterConnect(), which never runs when
// you are not connected — so on the login screen adminer answers "the action will be
// performed after successful login" and the design does not change. Picking a theme
// before you log in is a perfectly reasonable thing to want on a desktop app.
//
// Handled here rather than in a plugin hook because of timing, not taste: this file is
// included from bootstrap.inc.php:81, which is after session_start() at :51 and before
// any output, so the session write and the redirect both still work. Every hook a
// plugin can offer runs too late for one or the other.
if (isset($_POST["design"]) && Adminer\verify_token()) {
	Adminer\restart_session();
	$_SESSION["design"] = $_POST["design"];
	Adminer\redirect($_SERVER["REQUEST_URI"]);
}

return array(
	new AdminerDesktop(),
	new AdminerDesigns($designs),
);
