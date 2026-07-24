<?php
declare(strict_types=1);
namespace Desktop;

use Tracy\Debugger;

/** Turn Tracy on when the app was started with -debug.
*
* A fatal otherwise reaches the log as one line and the page as nothing; Tracy answers
* with the source, the values and the request that produced it, and gives dump() and
* Debugger::log() to reach for while chasing something.
*
* Never outside -debug, for the same reason the web inspector is not: it would hand that
* to anything that can reach the page.
*
* Adminer's CSP allows scripts by nonce, and Tracy's own are inline without one, so the
* debug bar stays blank — the error screen replaces the page and is unaffected.
*/
class Debug {
	static function enable(): void {
		if (!Env::Debug->get()) {
			return;
		}
		$dir = Env::DataDir->get();
		$log = $dir ? "$dir/log" : null;
		if ($log && !is_dir($log)) {
			@mkdir($log, 0700, true);
		}
		Debugger::enable(Debugger::Development, $log);

		// A bar panel that prints the user's persistent settings file, so the stored preferences
		// are one click away while debugging — both classes autoload, so the panel is built here
		// before adminer boots without pulling their files in by hand.
		Debugger::getBar()->addPanel(new SettingsPanel(new UserSettings()));

		// Enabling Tracy replaces adminer's own error handler, and with it the two things
		// include/errors.inc.php deliberately turns off: E_DEPRECATED (it targets older PHP
		// than we bundle) and the warning behind its bare $_GET["q"] style. Both come back as
		// a page of noise that buries whatever was worth reading.
		// Restored here for the files that ship verbatim from the adminer release only — in
		// our own, a missing key or a deprecation is ours to look at.
		$tracy = set_error_handler(function ($severity, $message, $file = '', $line = 0) use (&$tracy) {
			if (preg_match('~/(adminer|editor)\.php$|/adminer-plugins/~', str_replace('\\', '/', $file))
				&& ($severity == E_DEPRECATED
					|| (($severity & (E_WARNING | E_NOTICE)) && preg_match('~^Undefined (array key|offset|index)~', $message)))
			) {
				return true;
			}
			return $tracy($severity, $message, $file, $line);
		});
	}
}
