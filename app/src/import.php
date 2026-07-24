<?php
declare(strict_types=1);
namespace Desktop;

/** Let a pg_dump through adminer's SQL parser.
*
* Adminer splits an import into statements before running any of them, and inside a
* `COPY ... FROM stdin` block it keeps looking for SQL: an apostrophe in the data
* ("Equestria's divided") opens a string literal that closes at the next apostrophe rows
* away, and sooner or later one of those skipped runs swallows the `\.` that ends the
* block (sql.inc.php:83). The block then never ends where it should, so the COPY handling
* in the pgsql driver (drivers/pgsql.inc.php:195) does not recognise it, and the entire
* dump reaches the server as one statement: `syntax error at or near "s1"`.
*
* The data cannot stay as it is, and it must not be altered either -- so it is rewritten
* into escapes for the same bytes. COPY's text format decodes `\047` to an apostrophe, so
* the parser sees no quote and postgres stores exactly what pg_dump wrote.
*/
class Import {
	/** What adminer's parser reads as SQL, and the octal escape COPY turns back into it.
	*
	* `--` and `/*` only mean anything as a pair, so breaking the pair is enough: escaping
	* every dash would blow up every date in the dump to four times the size.
	*/
	private const ESCAPES = [
		"'" => '\047',
		'"' => '\042',
		'$' => '\044', // $tag$ dollar quoting
		'--' => '-\055',
		'/*' => '\057*',
	];

	/** Rewrite the import that is being posted, before adminer parses it. */
	static function defuse(): void {
		// Postgres only. This is how adminer decides it too (drivers/pgsql.inc.php:6):
		// the driver is the query string key, and DRIVER is defined off it. The constant
		// would say the same thing, but only for postgres -- every other driver defines
		// it after plugins are loaded, which is where this runs from.
		if (!isset($_GET["pgsql"])) {
			return;
		}
		if (isset($_POST["query"]) && is_string($_POST["query"])) {
			$_POST["query"] = self::escapeCopy($_POST["query"]);
		}
		// sql_file is a multiple upload, so every member of $_FILES is an array.
		$upload = $_FILES["sql_file"] ?? null;
		if (!$upload) {
			return;
		}
		$names = (array) $upload["name"];
		foreach ((array) $upload["tmp_name"] as $key => $tmp) {
			self::defuseFile((string) $tmp, (string) ($names[$key] ?? ""));
		}
	}

	/** Rewrite an uploaded file in place, leaving it where adminer will look for it. */
	private static function defuseFile(string $tmp, string $name): void {
		if (!is_uploaded_file($tmp)) {
			return;
		}
		// The same two ways get_file() reads it (functions.inc.php:557), so a gzipped
		// dump is unpacked here and packed again rather than quietly turned into text.
		// ponytail: whole file in memory, as get_file() does with it anyway.
		$gz = (bool) preg_match('~\.gz$~i', $name);
		$sql = file_get_contents($gz ? "compress.zlib://$tmp" : $tmp);
		if ($sql === false) {
			return;
		}
		$escaped = self::escapeCopy($sql);
		if ($escaped !== $sql) {
			file_put_contents($tmp, ($gz ? gzencode($escaped) : $escaped));
		}
	}

	/** Escape the data rows of every `COPY ... FROM stdin;` block. */
	static function escapeCopy(string $sql): string {
		// Nothing to do for the other 99% of imports, and it saves splitting them.
		if (stripos($sql, "FROM stdin;") === false) {
			return $sql;
		}
		$lines = explode("\n", $sql);
		$copy = false;
		foreach ($lines as $key => $line) {
			if (!$copy) {
				// Ending at `stdin;` is what makes this safe: that is COPY's text format,
				// the one that reads backslash escapes. A `WITH (FORMAT csv)` block would
				// store them literally, and it does not match.
				// pg_dump writes the statement on a single line; a hand-wrapped one is
				// left alone, which is where we were before this file existed.
				$copy = (bool) preg_match('~^\s*COPY\s.*\sFROM\s+stdin;\r?$~i', $line);
			} elseif (rtrim($line, "\r") === '\.') {
				$copy = false;
			} else {
				$lines[$key] = strtr($line, self::ESCAPES);
			}
		}
		return implode("\n", $lines);
	}
}
