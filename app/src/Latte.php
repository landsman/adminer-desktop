<?php
declare(strict_types=1);

namespace Desktop;

use Latte\Engine;
use Latte\Loaders\FileLoader;

/** The shared Latte engine for our own markup.
*
* Only our HTML goes through it: Adminer's own output stays Adminer's. Templates get
* absolute paths, so there is no root to keep in sync with where the files live.
*/
class Latte {
	/** Where a packaged build ships its templates already compiled; `make bundle`/`dist` fill it. */
	const string PRECOMPILED = __DIR__ . "/../latte-cache";

	static function engine(): Engine {
		static $latte;
		if (!$latte) {
			$latte = new Engine();
			// Latte names a compiled file by a hash of the template's path. Absolute, that path
			// differs on every machine, so a cache compiled by the build would never be found;
			// relative to app/ it is the same wherever the app was unpacked.
			$latte->setLoader(new class extends FileLoader {
				function getUniqueId(string $file): string {
					return str_replace(dirname(__DIR__), "", $file);
				}
			});
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
			// Our plugin strings, for a template handed no $desktop: an {embed}ded one such as
			// modal.latte sees only its arguments. Registered here so the linter and the build
			// compile the same call the app runs; the strings come from the AdminerDesktop the
			// request created, which hands itself over as the "desktop" provider.
			$latte->addFunction("t", fn(string $id): string => $latte->getProviders()["desktop"]->t($id));
			$dir = Env::DataDir->get();
			if (is_dir(self::PRECOMPILED) && PHP_OS_FAMILY != "Windows") {
				// A packaged build: its templates never change under it, so no freshness check —
				// with it off Latte only includes the file, no lock and no write, which is what
				// lets this live in a read-only, signed bundle. Windows locks even then.
				$latte->setCacheDirectory(self::PRECOMPILED);
				$latte->setAutoRefresh(false);
			} elseif ($dir && (is_dir("$dir/latte") || @mkdir("$dir/latte", 0700, true))) {
				$latte->setCacheDirectory("$dir/latte");
			}
			// Without a cache directory Latte compiles into memory on every request — correct,
			// just slower, which is what we get when the app is served without a data dir.
		}
		return $latte;
	}

	/** Compiles every template into PRECOMPILED, for a packaged build; returns how many. */
	static function precompile(): int {
		$latte = self::engine();
		$latte->setCacheDirectory(self::PRECOMPILED);
		// engine() turns the check off once latte-cache/ exists, and a stray one copied in from
		// the checkout would then ship as is: the file name hashes the path, not the content.
		$latte->setAutoRefresh(true);
		$files = Files::find(dirname(__DIR__) . "/src", "latte");
		foreach ($files as $file) {
			$latte->warmupCache($file);
		}
		return count($files);
	}
}
