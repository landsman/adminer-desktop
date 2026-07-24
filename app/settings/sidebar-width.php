<?php
declare(strict_types=1);
/** Persist the width the user dragged the sidebar to (issue #11).
*
* A bare endpoint, served directly by php-server like screenshot.php beside it — it stores
* one number and has no business booting the whole application to do it.
* desktop/javascript/sidebar-resize.js posts here on release; AdminerDesktop::head() reads it
* back on the next load and emits it before paint.
*/

require_once __DIR__ . "/../config.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
	http_response_code(405);
	exit;
}

// ponytail: no CSRF token. The server binds a private random localhost port that only the
// app's own webview reaches, and the sole writable thing here is one integer clamped to a
// sane pixel range — not worth threading adminer's token out to a background beacon. Revisit
// if the server ever listens on anything but loopback.
$width = filter_input(INPUT_POST, "width", FILTER_VALIDATE_INT);
if ($width === false || $width === null) {
	http_response_code(400);
	exit;
}

// Clamp to the same range the drag handle enforces, so a crafted post can't wedge the
// sidebar off-screen or down to nothing.
$width = max(180, min(640, $width));

(new Desktop\Config())->set("sidebar_width", $width);
http_response_code(204);
