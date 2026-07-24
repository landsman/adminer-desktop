<?php
declare(strict_types=1);
/** Serve a design's preview image, fetched once from adminer.org and cached on disk.
*
* Served directly by the web server, not through adminer: it is an image endpoint and
* has no business booting the whole application to answer.
*
* Always returns an image. A missing design, a 404 upstream, no network at all — every
* failure ends at the same placeholder, so the table never renders a broken-image icon.
*/

require_once __DIR__ . "/../../env.php";

// The designs sit beside this file, under settings/theme/.
$root = str_replace('\\', '/', __DIR__);
$name = (string) ($_GET["design"] ?? "");

/** Send a cached screenshot.
* nosniff because the body is bytes fetched from another host: the PNG magic number is
* checked before anything is written to the cache, and this stops a browser second
* guessing the content type anyway.
*/
function serve(string $filename): void {
	header("Content-Type: image/png");
	header("X-Content-Type-Options: nosniff");
	header("Cache-Control: max-age=86400");
	readfile($filename);
	exit;
}

function placeholder(): void {
	// Redirect to the shared file rather than emitting the markup here: every design
	// without a preview then points at one URL the browser caches once, instead of each
	// fetching its own identical copy. The redirect itself is deliberately not cached,
	// so a design gets a real screenshot as soon as one becomes reachable.
	header("Cache-Control: no-store");
	header("Location: placeholder.svg", true, 302);
	exit;
}

// $design is assigned from the filesystem, never from the query string. The name only
// ever takes part in a comparison, so no path below is built out of user input -- which
// is what makes this safe, and is also why it needs no scanner suppression to say so.
//
// The previous version checked a pattern plus is_dir(), and that was wrong: the pattern
// allowed dots, so ".." matched it, and is_dir() on the parent directory is true.
$design = null;
foreach ((array) glob("$root/designs/*", GLOB_ONLYDIR) as $dir) {
	if (basename($dir) === $name) {
		$design = basename($dir);
		break;
	}
}
if ($design === null) {
	placeholder();
}

// The launcher passes the data directory in; without it (running `make serve` by hand)
// fall back to temp, where a lost cache costs only a re-fetch.
$dir = (\Desktop\Env::Data->get() ?: sys_get_temp_dir()) . "/screenshots";
$file = "$dir/$design.png";
$miss = "$dir/$design.miss";

if (is_file($file) && filesize($file) > 0) {
	serve($file);
}

// Remember failures too, or a design with no screenshot upstream — adminer-dark has
// none — re-tries the network on every single page render.
if (is_file($miss) && filemtime($miss) > time() - 86400) {
	placeholder();
}

$context = stream_context_create(["http" => [
	"timeout" => 5, // offline should look like a placeholder, not like a hung page
	"user_agent" => "adminer-desktop",
]]);
$body = @file_get_contents("https://www.adminer.org/static/designs/$design/screenshot.png", false, $context);

@mkdir($dir, 0700, true);
// Check the magic number, not just that something came back: an error page is a 200 with
// HTML in it, and caching that would poison the entry until it expired.
if ($body !== false && strncmp($body, "\x89PNG", 4) === 0) {
	file_put_contents($file, $body);
	serve($file);
}

@touch($miss);
placeholder();
