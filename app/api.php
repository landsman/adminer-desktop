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

/** action => the handler it runs. A handler is a class with a static handle() that does the
* work and returns the HTTP status to answer with — no base class, there is nothing to share.
* @var array<string,class-string> */
$actions = [
	"resize" => Desktop\Api\ResizePreference::class,
];

$action = (string) filter_input(INPUT_GET, "action");
if (!isset($actions[$action])) {
	http_response_code(404);
	exit;
}
// Every endpoint here writes; nothing is readable over this URL, so the method check is the
// entry's business rather than each handler's.
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
	http_response_code(405);
	exit;
}

// ponytail: no CSRF token. The server binds a private random localhost port that only the
// app's own webview reaches, and what these handlers write is the user's own UI preferences,
// each validated and clamped. Not worth threading adminer's token out to a background beacon —
// revisit if the server ever listens on anything but loopback, or an action does more.
http_response_code($actions[$action]::handle());
