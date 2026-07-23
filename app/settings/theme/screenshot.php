<?php
/** Serve a design's preview image, fetched once from adminer.org and cached on disk.
*
* Served directly by the web server, not through adminer: it is an image endpoint and
* has no business booting the whole application to answer.
*
* Always returns an image. A missing design, a 404 upstream, no network at all — every
* failure ends at the same placeholder, so the table never renders a broken-image icon.
*/

// The designs sit beside this file, under settings/theme/.
$root = str_replace('\\', '/', __DIR__);
$name = (string) ($_GET["design"] ?? "");

function placeholder(): void {
	// Redirect to the shared file rather than emitting the markup here: every design
	// without a preview then points at one URL the browser caches once, instead of each
	// fetching its own identical copy. The redirect itself is deliberately not cached,
	// so a design gets a real screenshot as soon as one becomes reachable.
	header("Cache-Control: no-store");
	header("Location: placeholder.svg", true, 302);
	exit;
}

// Whitelist by existence: the name reaches a filesystem path and a URL, so it is checked
// against the directories we actually ship rather than merely pattern-matched.
if (!preg_match('~^[\w.-]+$~', $name) || !is_dir("$root/designs/$name")) {
	placeholder();
}

// The launcher passes the data directory in; without it (running `make serve` by hand)
// fall back to temp, where a lost cache costs only a re-fetch.
$dir = (getenv("ADMINER_DESKTOP_DATA") ?: sys_get_temp_dir()) . "/screenshots";
$file = "$dir/$name.png";
$miss = "$dir/$name.miss";

if (is_file($file) && filesize($file) > 0) {
	header("Content-Type: image/png");
	header("Cache-Control: max-age=86400");
	readfile($file);
	exit;
}

// Remember failures too, or a design with no screenshot upstream — adminer-dark has
// none — re-tries the network on every single page render.
if (is_file($miss) && filemtime($miss) > time() - 86400) {
	placeholder();
}

$context = stream_context_create(array("http" => array(
	"timeout" => 5, // offline should look like a placeholder, not like a hung page
	"user_agent" => "adminer-desktop",
)));
$body = @file_get_contents("https://www.adminer.org/static/designs/$name/screenshot.png", false, $context);

@mkdir($dir, 0700, true);
// Check the magic number, not just that something came back: an error page is a 200 with
// HTML in it, and caching that would poison the entry until it expired.
if ($body !== false && strncmp($body, "\x89PNG", 4) === 0) {
	file_put_contents($file, $body);
	header("Content-Type: image/png");
	header("Cache-Control: max-age=86400");
	echo $body;
	exit;
}

@touch($miss);
placeholder();
