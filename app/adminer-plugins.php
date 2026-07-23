<?php
declare(strict_types=1);
// Plugins that need constructor arguments must be instantiated here; everything the
// user drops into adminer-plugins/ is picked up automatically (and auto-enabled) by
// adminer itself — see include/plugins.inc.php:17.

// First, so that a fatal in anything below still lands on Tracy's screen rather than in
// a blank page. Does nothing unless the app was started with -debug.
require_once __DIR__ . "/debug.php";
Desktop\debug();

// Ours, always on — it is app behaviour, not an optional plugin, so it lives here
// rather than in adminer-plugins/ which is the user's own enabled set.
require_once __DIR__ . "/desktop.php";

// Design switching is handled by AdminerDesktop, not upstream plugins/designs.php:
// that one only applies a switch from afterConnect(), which never runs when
// you are not connected — so on the login screen adminer answers "the action will be
// performed after successful login" and the design does not change. Picking a theme
// before you log in is a perfectly reasonable thing to want on a desktop app.
//
// Handled here rather than in a plugin hook because of timing, not taste: this file is
// included from bootstrap.inc.php:81, which is after session_start() at :51 and before
// any output, so the session write and the redirect both still work. Every hook a
// plugin can offer runs too late for one or the other.
$desktop = new AdminerDesktop();
$desktop->handlePost();

return array(
	$desktop,
);
