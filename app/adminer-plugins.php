<?php
declare(strict_types=1);
// Every plugin adminer runs is instantiated here — ours and the ones the user enabled in
// settings. Adminer offers a second route, a directory of files it globs and auto-enables
// (include/plugins.inc.php:17), which we deliberately do not use: it can only construct a
// class with no arguments, it enables whatever it finds with no say from the app, and the
// same class reached through both routes at once is a fatal redeclare.

// Turn the autoloader on first (the app is PSR-4, Desktop\ -> src/), then Tracy — so a fatal
// in anything below still lands on Tracy's screen rather than a blank page. Debug::enable()
// does nothing unless the app was started with -debug.
require_once __DIR__ . "/vendor/autoload.php";
Desktop\Debug::enable();

// Ours, always on — it is app behaviour, not an optional plugin. Global namespace and named
// Adminer*, so it is not part of the Desktop\ PSR-4 tree; required, not autoloaded.
require_once __DIR__ . "/AdminerDesktop.php";

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

// handlePost() first: ticking a plugin and seeing it take effect on the same request is the
// whole point of saving before this line rather than after it.
return array_merge([$desktop], $desktop->plugins()->instances());
