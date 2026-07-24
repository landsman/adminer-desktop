<?php
declare(strict_types=1);
namespace Desktop;

// vendor/ sits one level up in app/, beside this src/ dir — the same place in the checkout
// and in a packaged build, where app/ is the whole tree that ships. Loading it turns on
// autoloading for every Desktop\ class and eagerly defines env() (a function, so it is a
// files autoload, not a class the loader can find on demand).
require_once dirname(__DIR__) . "/vendor/autoload.php";

/** The shared Latte engine for our own markup.
*
* Only our HTML goes through it: Adminer's own output stays Adminer's. Templates get
* absolute paths, so there is no root to keep in sync with where the files live.
*/
function latte(): \Latte\Engine {
	static $latte;
	if (!$latte) {
		$latte = new \Latte\Engine();
		// Latte's own bridge to Tracy: an error in a template then points at the line in the
		// .latte file rather than at the compiled PHP. Idle when Tracy is off, so no branch.
		$latte->addExtension(new \Latte\Bridges\Tracy\TracyExtension());
		// Adminer's helpers are plain functions in its own namespace, and a fully qualified
		// call inside a template is something an editor reads as a class name. Registered,
		// they are {input_token()} — and the list is also what a template may reach for.
		// Wrapped rather than passed as first-class callables, which would have to exist
		// already: the linter builds this engine with no adminer loaded at all.
		$latte->addFunction("input_hidden", fn(string $name, $value = 1) => \Adminer\input_hidden($name, $value));
		$latte->addFunction("input_token", fn() => \Adminer\input_token());
		// Without a cache directory Latte compiles into memory on every request — correct,
		// just slower, which is what we get when the app is served without a data dir.
		$dir = env(Env::DataDir);
		if ($dir && (is_dir("$dir/latte") || @mkdir("$dir/latte", 0700, true))) {
			$latte->setCacheDirectory("$dir/latte");
		}
	}
	return $latte;
}
