<?php
declare(strict_types=1);
// Does a pg_dump survive adminer's SQL parser after Desktop\Import has been over it?
// Run by `make qa`. The two patterns below are adminer's own, quoted from the files
// named, because they are the thing that has to match -- copying them is what makes this
// test fail if an upgrade changes how a COPY block is recognised.

require_once __DIR__ . "/../app/import.php";

$fails = 0;
$check = function (string $name, bool $ok) use (&$fails): void {
	echo ($ok ? "ok: " : "FAIL: "), $name, "\n";
	$fails += ($ok ? 0 : 1);
};

// Everything adminer's parser reacts to, in data pg_dump would write verbatim.
$dump = "--\n-- Data for Name: shows; Type: TABLE DATA\n--\n\n"
	. "COPY public.shows (id, title, note) FROM stdin;\n"
	. "1\tEquestria's divided\ta \"quoted\" title\n"
	. "2\tO'Dowd\tcost: \$100 -- not a comment /* nor this */\n"
	. "3\t\\N\tbackslash \\\\ and a tab \\t stay escapes\n"
	. "\\.\n"
	. "\n\nSELECT 'an apostrophe outside the block';\n";
$escaped = Desktop\Import::escapeCopy($dump);

$rows = explode("\n", $escaped);
$data = implode("\n", array_slice($rows, 5, 3));
$check("no SQL-significant character is left in the data", !preg_match('~[\'"$]|--|/\*~', $data));
$check("the terminator line survives", in_array('\.', $rows, true));
$check("the tab layout is untouched", substr_count($data, "\t") === substr_count("1\tx\ty\n2\tx\ty\n3\tx\ty", "\t"));
$check("existing escapes are untouched", strpos($data, '\N') !== false && strpos($data, '\\\\') !== false);
$check("SQL outside the block is untouched", strpos($escaped, "SELECT 'an apostrophe outside the block';") !== false);
$check("nothing to escape is returned as it was", Desktop\Import::escapeCopy("SELECT 'x'; -- /*\n") === "SELECT 'x'; -- /*\n");
// CSV blocks do not decode backslash escapes, so they must be left alone.
$csv = "COPY t (a) FROM stdin WITH (FORMAT csv);\nit's csv\n\\.\n";
$check("a CSV block is left alone", Desktop\Import::escapeCopy($csv . "COPY u (a) FROM stdin;\n") === $csv . "COPY u (a) FROM stdin;\n");

// adminer/sql.inc.php:76-92, splitting the import into statements.
$line_comment = '--';
$space = "(?:\\s|/\\*[\s\S]*?\\*/|(?:#|$line_comment)[^\n]*\n?|--\r?\n)";
$parse = '[\'"]|/\*|' . $line_comment . '|$|\$([a-zA-Z]\w*)?\$';
$query = $escaped;
$delimiter = ";";
$offset = 0;
$commands = array();
while ($query != "") {
	if (!$offset && preg_match("~^($space*+COPY\\s+)[^;]+\\s+FROM\\s+stdin;~i", $query, $match)) {
		$delimiter = "\n\\\\\\.\r?\n";
		$offset = strlen($match[0]);
		continue;
	}
	preg_match("($delimiter\\s*|$parse)", $query, $match, PREG_OFFSET_CAPTURE, $offset);
	list($found, $pos) = $match[0];
	if (!$found && rtrim($query) == "") {
		break;
	}
	$offset = $pos + strlen($found);
	if ($found && !preg_match("(^$delimiter)", $found)) { // inside a string or a comment
		$pattern = ($found == '/*' ? '\*/' : (preg_match("~^$line_comment|^#~", $found) ? "\n" : preg_quote($found)));
		preg_match("($pattern|\$)s", $query, $match, PREG_OFFSET_CAPTURE, $offset);
		$offset = $match[0][1] + strlen($match[0][0]);
		continue;
	}
	$commands[] = substr($query, 0, $pos + ($delimiter[0] == "\n" ? 3 : 0));
	$query = substr($query, $offset);
	$offset = 0;
	$delimiter = ($delimiter[0] == "\n" ? ";" : $delimiter);
}

$copy = "";
foreach ($commands as $command) {
	if (stripos($command, "COPY ") !== false) {
		$copy = $command;
	}
}
$check("the COPY block is one statement, ending at the terminator", substr($copy, -3) === "\n" . '\.');
// adminer/drivers/pgsql.inc.php:195 -- what turns that statement into pg_copy_from().
$check("the pgsql driver recognises it as a COPY", (bool) preg_match('~\bCOPY\s+(.+?)\s+FROM\s+stdin;\n?(.*)\n\\\\\.$~is', $copy, $match));
$check("all three rows are handed over", isset($match[2]) && count(explode("\n", $match[2])) === 3);

exit($fails ? 1 : 0);
