<?php
declare(strict_types=1);
/** The one URL the page's own scripts talk to — everything the app answers that is not adminer.
*
* A bare entry, served directly by php-server: it boots the autoloader, checks the method and
* hands the request to the handler named by ?action=. Adding an endpoint is a class in
* src/Api/ plus a line in the table below, not another file at a URL — which is what the two
* one-off endpoints before it had grown into.
*
* The table is also the allowlist: an action that is not in it is a 404, so nothing under
* src/Api/ is reachable by guessing a class name. Its mirror on the page is
* src/Assets/javascript/api.js.
*/

require_once __DIR__ . "/vendor/autoload.php";

// Under -debug only, and a no-op otherwise. Tracy's bar is no use to a request whose answer
// is a 204 nobody looks at — a beacon fired as the page is being torn down — but its logger
// is: a fatal in a handler reaches the log with the source and the values instead of
// vanishing into a failed request, and the line below records what every action did.
Desktop\Debug::enable();
// And no bar: there is no page here to put one on, and Tracy would otherwise append its
// markup to every answer that is allowed a body — 10 kB of it onto a 400 nobody renders.
Tracy\Debugger::$showBar = false;

/** action => the handler it runs. A handler is a class with a static handle() that does the
* work and returns the HTTP status to answer with — no base class, there is nothing to share.
* @var array<string,class-string> */
$actions = [
	"resize" => Desktop\Api\ResizePreference::class,
];

// One status out of every path rather than an early exit each, so the log below sees the
// rejected requests too — a beacon posting an action that isn't there is exactly what someone
// is debugging when they come looking.
//
// ponytail: no CSRF token. The server binds a private random localhost port that only the
// app's own webview reaches, and what these handlers write is the user's own UI preferences,
// each validated and clamped. Not worth threading adminer's token out to a background beacon —
// revisit if the server ever listens on anything but loopback, or an action does more.
$action = (string) filter_input(INPUT_GET, "action");
if (!isset($actions[$action])) {
	$status = 404;
} elseif ($_SERVER["REQUEST_METHOD"] !== "POST") {
	// Every action here writes; nothing is readable over this URL, so the method check is the
	// entry's business rather than each handler's.
	$status = 405;
} else {
	$status = $actions[$action]::handle();
}

// What arrived and what it answered, one line per request in the log dir's api.log — here
// rather than in each handler, so an action added later is traced without remembering to.
// `make logs` opens the folder.
if (Tracy\Debugger::isEnabled()) {
	Tracy\Debugger::log("$action $status " . json_encode($_POST), "api");
}

http_response_code($status);
