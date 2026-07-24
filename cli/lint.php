<?php
declare(strict_types=1);
/** Syntax and style check for the PHP we ship.
*
* Run through the frankenphp we already download, so QA needs nothing installed:
*   ./bin/frankenphp php-cli cli/lint.php
*
* A parse error here is not a lint nicety — adminer includes these files on every
* request, so a bad one is a blank white app with the reason buried in the log.
*
* This stays dependency-free on purpose: it runs before `mise run install` on a fresh clone,
* where a parse error would otherwise blank the app. The deeper checks that need composer —
* phpstan for types, phpcs (with slevomat) for the conventions — run in `make qa` through the
* same frankenphp once the deps are in; keep new rules there, not here.
*/

// The repo root, one level up now that this lives in cli/: every path below is resolved
// against it rather than against this file's own directory.
$root = dirname(__DIR__);

// Shipped verbatim from the adminer release, not ours: linting them would report
// upstream's choices as our problems, and they are checksum-verified anyway.
$vendored = [
	"app/adminer.php",
	"app/editor.php",
	"/settings/plugins/available/",
	// Whatever the user has enabled is a copy of one of those, or a file they dropped in
	// themselves. Either way it is not ours to have opinions about.
	"/adminer-plugins/",
	"/settings/theme/designs/",
];

$errors = 0;
// Required, not autoloaded: this is the linter, so it has to work before anything else
// does. Desktop\Files is app code because app code will want it too.
require_once $root . "/app/files.php";

$filenames = array_merge(
	Desktop\Files::find($root . "/app", "php", $vendored),
	[__FILE__]
);

foreach ($filenames as $filename) {
	$short = str_replace($root . "/", "", $filename);
	$source = file_get_contents($filename);

	// TOKEN_PARSE makes the tokenizer validate, so this is `php -l` without executing
	// the file — which matters, since including these would need adminer loaded first.
	try {
		// The ParseError is the point, but the token list is checked too so the call is
		// visibly doing something: any PHP file has at least an open tag to tokenize.
		if (!token_get_all($source, TOKEN_PARSE)) {
			fwrite(STDERR, "$short: no PHP tokens\n");
			$errors++;
			continue;
		}
	} catch (ParseError $e) {
		fwrite(STDERR, "$short: syntax: " . $e->getMessage() . "\n");
		$errors++;
		continue; // style checks on a file that does not parse are just noise
	}

	// Adminer is tab-indented and enforces it (Generic.WhiteSpace.DisallowSpaceIndent);
	// these files sit next to adminer's own, so they follow adminer's convention.
	foreach (explode("\n", $source) as $i => $line) {
		$n = $i + 1;
		if (preg_match('~^ +~', $line)) {
			fwrite(STDERR, "$short:$n: space indentation, adminer uses tabs\n");
			$errors++;
		}
		if (preg_match('~[ \t]+$~', $line)) {
			fwrite(STDERR, "$short:$n: trailing whitespace\n");
			$errors++;
		}
	}
	if ($source !== "" && substr($source, -1) !== "\n") {
		fwrite(STDERR, "$short: no newline at end of file\n");
		$errors++;
	}
	// Enforced rather than remembered: strict_types only applies to the file that
	// declares it, so one file missing it is a silent hole rather than an error.
	if (!preg_match('~^<\?php\s*\ndeclare\(strict_types=1\);~', $source)) {
		fwrite(STDERR, "$short: missing declare(strict_types=1) on the line after <?php\n");
		$errors++;
	}

	// That superglobal is banned outright in adminer: it merges GET, POST and COOKIE, so it
	// silently accepts a value from a source the code did not intend.
	// Needle split, in the message too, so this file does not match its own rule — it
	// lints itself, and a checker that cannot survive its own check is worth nothing.
	$banned = '$_' . 'REQUEST';
	if (strpos($source, $banned) !== false) {
		fwrite(STDERR, "$short: uses $banned\n");
		$errors++;
	}
}

echo ($errors ? "$errors problem(s)\n" : "php ok\n");

// The templates, through the same engine the app builds — so the linter knows the
// functions registered on it and does not report them as unknown. Skipped rather than
// failed when the deps are not installed, like the JS tooling in the Makefile: this file
// is also what a fresh clone runs before `mise run install`.
if (file_exists($root . "/vendor/autoload.php")) {
	require_once $root . "/app/latte.php";
	if (!(new Latte\Tools\Linter(Desktop\latte()))->scanDirectory($root . "/app")) {
		$errors++;
	}
} else {
	echo "latte skipped (run `mise run install`)\n";
}

exit($errors ? 1 : 0);
