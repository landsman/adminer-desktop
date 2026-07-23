<?php
/** Syntax and style check for the PHP we ship.
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

// adminer.php and editor.php are downloaded release artifacts, not ours: linting them
// would report upstream's choices as our problems, and they are checksum-verified anyway.
$vendored = array("adminer.php", "editor.php");

$errors = 0;
// Recursive: the plugin is split across app/settings/ now, and a linter that only
// looks at the top level is a linter that stops noticing.
foreach (array_merge(glob(__DIR__ . "/app/*.php"), glob(__DIR__ . "/app/settings/*.php"), glob(__DIR__ . "/app/settings/*/*.php"), array(__FILE__)) as $filename) {
	$short = basename($filename);
	if (in_array($short, $vendored)) {
		continue;
	}
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
exit($errors ? 1 : 0);
