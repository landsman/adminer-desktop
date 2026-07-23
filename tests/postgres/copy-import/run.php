<?php
declare(strict_types=1);

/** Does a pg_dump survive adminer's SQL parser once Desktop\Import has been over it?
 *
 * dump.sql next door is the fixture — a miniature pg_dump whose COPY data holds every
 * character adminer reads as SQL. The patterns below are adminer's own, quoted from the
 * files named, because they are what has to match: copying them is what makes this fail
 * if an upgrade changes how a COPY block is recognised, rather than someone's import.
 *
 * No database and no browser, so `make qa` runs it. Correctness of the escapes against a
 * real server is the other half, and that one needs postgres — see the PR.
 */

require_once dirname(__DIR__, 3) . "/app/import.php";

$fails = 0;
$check = function (string $name, bool $ok) use (&$fails): void {
	echo ($ok ? "ok: " : "FAIL: "), $name, "\n";
	$fails += ($ok ? 0 : 1);
};

/** The data rows of the first COPY block, which is what all of this is about. */
$data = function (string $sql): string {
	preg_match('~FROM stdin;\n(.*)\n\\\\\.~sU', $sql, $match);
	return $match[1];
};

$dump = (string) file_get_contents(__DIR__ . "/dump.sql");
$escaped = Desktop\Import::escapeCopy($dump);

$check("no SQL-significant character is left in the data", !preg_match('~[\'"$]|--|/\*~', $data($escaped)));
$check("the rows are still rows", substr_count($data($escaped), "\n") === substr_count($data($dump), "\n"));
$check("the columns are still columns", substr_count($data($escaped), "\t") === substr_count($data($dump), "\t"));
$check("COPY's own escapes are untouched", strpos($data($escaped), '\N') !== false && strpos($data($escaped), '\\\\') !== false);
$check("everything before the block is untouched", strstr($escaped, "COPY ", true) === strstr($dump, "COPY ", true));
$check("everything after it is untouched", strstr($escaped, "\n\\.") === strstr($dump, "\n\\."));
$check("a dump with nothing to escape comes back as it was", Desktop\Import::escapeCopy("SELECT 'x'; -- /*\n") === "SELECT 'x'; -- /*\n");
// CSV blocks do not decode backslash escapes, so they have to be left alone.
$csv = "COPY t (a) FROM stdin WITH (FORMAT csv);\nit's csv\n\\.\n";
$check("a CSV block is left alone", Desktop\Import::escapeCopy($csv) === $csv);

// adminer/sql.inc.php:76-92, splitting an import into statements.
$copyStatement = function (string $sql): string {
	$line_comment = '--';
	$space = "(?:\\s|/\\*[\s\S]*?\\*/|(?:#|$line_comment)[^\n]*\n?|--\r?\n)";
	$parse = '[\'"]|/\*|' . $line_comment . '|$|\$([a-zA-Z]\w*)?\$';
	$query = $sql;
	$delimiter = ";";
	$offset = 0;
	$return = "";
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
		$command = substr($query, 0, $pos + ($delimiter[0] == "\n" ? 3 : 0));
		if (stripos($command, "COPY ") !== false) {
			$return = $command;
		}
		$query = substr($query, $offset);
		$offset = 0;
		$delimiter = ($delimiter[0] == "\n" ? ";" : $delimiter);
	}
	return $return;
};

// The fixture has to be worth having: without the escaping it must still break, or these
// checks would pass on a dump that never had the problem.
$check("the fixture does break the parser on its own", substr($copyStatement($dump), -3) !== "\n" . '\.');

$copy = $copyStatement($escaped);
$check("the COPY block comes out as one statement, ending at the terminator", substr($copy, -3) === "\n" . '\.');
// adminer/drivers/pgsql.inc.php:195 — what turns that statement into pg_copy_from().
$check("the pgsql driver recognises it as a COPY", (bool) preg_match('~\bCOPY\s+(.+?)\s+FROM\s+stdin;\n?(.*)\n\\\\\.$~is', $copy, $match));
$check("every row is handed over", isset($match[2]) && count(explode("\n", $match[2])) === count(explode("\n", $data($dump))));

exit($fails ? 1 : 0);
