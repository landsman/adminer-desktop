<?php
declare(strict_types=1);
namespace Desktop;

// vendor/ sits next to app/ in the checkout and inside it in a packaged build, because
// the packaging copies it in — app/ is the only tree that ships.
require_once (file_exists(__DIR__ . "/vendor/autoload.php") ? __DIR__ : dirname(__DIR__)) . "/vendor/autoload.php";

/** The shared Latte engine for our own markup.
*
* Only our HTML goes through it: Adminer's own output stays Adminer's. Templates get
* absolute paths, so there is no root to keep in sync with where the files live.
*/
function latte(): \Latte\Engine {
	static $latte;
	if (!$latte) {
		$latte = new \Latte\Engine();
		// Adminer's helpers are plain functions in its own namespace, and a fully qualified
		// call inside a template is something an editor reads as a class name. Registered,
		// they are {input_token()} — and the list is also what a template may reach for.
		$latte->addFunction("input_hidden", \Adminer\input_hidden(...));
		$latte->addFunction("input_token", \Adminer\input_token(...));
		// Without a cache directory Latte compiles into memory on every request — correct,
		// just slower, which is what we get when the app is served without a data dir.
		$dir = getenv("ADMINER_DESKTOP_DATA");
		if ($dir && (is_dir("$dir/latte") || @mkdir("$dir/latte", 0700, true))) {
			$latte->setCacheDirectory("$dir/latte");
		}
	}
	return $latte;
}
