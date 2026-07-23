<?php
declare(strict_types=1);
/** Syntax and style checks for the PHP and CSS we ship.
*
* Run through the frankenphp we already download, so QA needs nothing installed:
*   ./bin/frankenphp php-cli lint.php
*
* A parse error here is not a lint nicety — adminer includes these files on every
* request, so a bad one is a blank white app with the reason buried in the log.
*
* ponytail: no phpcs, no phpstan. Those need composer and a php install this repo
* deliberately does not have, to check ~250 lines. Add them if this file starts
* growing rules instead of catching bugs.
*/

// Shipped verbatim from the adminer release, not ours: linting them would report
// upstream's choices as our problems, and they are checksum-verified anyway.
$vendored = array(
	"app/adminer.php",
	"app/editor.php",
	"/settings/plugins/available/",
	// Whatever the user has enabled is a copy of one of those, or a file they dropped in
	// themselves. Either way it is not ours to have opinions about.
	"/adminer-plugins/",
	"/settings/theme/designs/",
);

$errors = 0;
// Required, not autoloaded: this is the linter, so it has to work before anything else
// does. Desktop\Files is app code because app code will want it too.
require_once __DIR__ . "/app/files.php";

$filenames = array_merge(
	Desktop\Files::find(__DIR__ . "/app", "php", $vendored),
	array(__FILE__)
);

foreach ($filenames as $filename) {
	$short = str_replace(__DIR__ . "/", "", $filename);
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

// CSS we author (not the vendored gallery designs) keeps one declaration per line: diffs
// then show which property changed instead of a whole reflowed rule, and a stray or
// duplicated property is obvious. Checked here rather than with stylelint, which would
// need the node install this repo deliberately does not have.
$css = array_merge(
	(array) glob(__DIR__ . "/app/styles/css/*.css"),
	(array) glob(__DIR__ . "/app/settings/theme/designs/adminer-desktop/*.css")
);
foreach ($css as $filename) {
	$short = str_replace(__DIR__ . "/", "", $filename);
	// Blank out block comments but keep their newlines, so reported line numbers stay
	// right and a semicolon inside a comment is not counted as a declaration.
	// ponytail: no string/data-URI awareness — a `;` inside a value (a base64 data URI)
	// would false-positive. None exist in our CSS; add a scanner if that changes.
	$stripped = preg_replace_callback('~/\*.*?\*/~s', function ($m) {
		return str_repeat("\n", substr_count($m[0], "\n"));
	}, (string) file_get_contents($filename));
	foreach (explode("\n", $stripped) as $i => $line) {
		$n = $i + 1;
		$semicolons = substr_count($line, ";");
		$brace = strpos($line, "{") !== false || strpos($line, "}") !== false;
		if ($semicolons > 1) {
			fwrite(STDERR, "$short:$n: more than one declaration on a line\n");
			$errors++;
		} elseif ($semicolons === 1 && $brace) {
			fwrite(STDERR, "$short:$n: declaration shares a line with a brace\n");
			$errors++;
		}
	}
}

echo ($errors ? "$errors problem(s)\n" : "lint ok\n");
exit($errors ? 1 : 0);
