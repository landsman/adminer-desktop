<?php
declare(strict_types=1);
namespace Desktop;

// vendor/ sits next to app/ in the checkout and inside it in a packaged build, because
// the packaging copies it in — app/ is the only tree that ships.
require_once (file_exists(__DIR__ . "/vendor/autoload.php") ? __DIR__ : dirname(__DIR__)) . "/vendor/autoload.php";

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
function debug(): void {
	if (!getenv("ADMINER_DESKTOP_DEBUG")) {
		return;
	}
	$dir = getenv("ADMINER_DESKTOP_DATA");
	$log = $dir ? "$dir/log" : null;
	if ($log && !is_dir($log)) {
		@mkdir($log, 0700, true);
	}
	\Tracy\Debugger::enable(\Tracy\Debugger::Development, $log);

	// Adminer's style is a bare $_POST["x"], and it silences the warning that follows in
	// include/errors.inc.php. Enabling Tracy replaces that handler, so every one of them
	// comes back and buries whatever was worth reading under a page of noise. Same rule,
	// same messages as upstream; everything else goes on to Tracy.
	$tracy = set_error_handler(function ($severity, $message, $file = '', $line = 0) use (&$tracy) {
		if (($severity & (E_WARNING | E_NOTICE)) && preg_match('~^Undefined (array key|offset|index)~', $message)) {
			return true;
		}
		return $tracy($severity, $message, $file, $line);
	});
}
