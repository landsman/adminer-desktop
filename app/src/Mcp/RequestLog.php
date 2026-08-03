<?php
declare(strict_types=1);

namespace Desktop\Mcp;

use Desktop\Env;

/** What an agent asked this window, one line per request, a file per day.
*
* The panel says an agent queried recently; this says what it asked. That is the difference
* between noticing something and being able to check it, and for a feature whose whole job is
* handing a database to something that acts on its own, being able to check it is the point.
*
* Rotation is the filename. mcp-2026-08-03.log is today's by construction, so a new day starts
* a new file with no renaming, no size check and nothing to race: two processes appending on
* either side of midnight simply write to the two files they each named. Yesterday's is left
* exactly as it was rather than being rewritten, which is what makes it evidence.
*
* It lives in the launcher's log directory, so the Open Logs menu item already opens it — on
* macOS that is ~/Library/Logs/Adminer Desktop. Nothing new to wire into the menu.
*
* What is written is the request, never the answer: the method, the tool, and the shape of what
* was asked. A query's *results* are the contents of the database, and copying those into a
* plaintext file beside it would be a worse leak than the thing being audited.
*/
class RequestLog {
	/** Days of history kept. Enough to answer "what did it do while I was away for the
	* weekend"; past that the files are deleted on the next write, so a machine left running
	* does not accumulate them forever. */
	private const int KEEP_DAYS = 14;

	/** A logged line is truncated here. SQL can be arbitrarily long and the point is to see
	* what was asked, not to reproduce it byte for byte. */
	private const int MAX_DETAIL = 500;

	private ?string $dir;

	function __construct(?string $dir = null) {
		$dir = $dir ?? (Env::Logs->get() ?: null);
		$this->dir = $dir !== null && $dir !== '' ? $dir : null;
	}

	/** Today's file. Public so a check can look at it without recomputing the name. */
	function path(int $now): ?string {
		return $this->dir !== null ? $this->dir . '/mcp-' . gmdate('Y-m-d', $now) . '.log' : null;
	}

	/** Append one request.
	*
	* @param string $method the JSON-RPC method
	* @param string $tool the tool name for tools/call, empty otherwise
	* @param string $detail the argument worth seeing — a table name, or the SQL
	*/
	function append(string $method, string $tool, string $detail, int $now): void {
		$path = $this->path($now);
		if ($path === null) {
			return;
		}
		if (!is_dir($this->dir ?? '')) {
			@mkdir($this->dir ?? '', 0700, true);
		}
		$line = implode("\t", [
			gmdate('Y-m-d\TH:i:s\Z', $now),
			$method,
			$tool,
			$this->oneLine($detail),
		]);
		// FILE_APPEND with a single write: the O_APPEND the flag maps to is atomic for a write
		// this size, so two processes cannot interleave halves of a line.
		@file_put_contents($path, $line . "\n", FILE_APPEND);
		@chmod($path, 0600); // it names tables and queries from your database
		$this->prune($now);
	}

	/** Delete anything older than KEEP_DAYS.
	*
	* Driven by the date in the filename rather than mtime: the name is what the writer chose,
	* and a file copied or restored keeps its meaning rather than being judged by when it landed.
	*/
	private function prune(int $now): void {
		if ($this->dir === null) {
			return;
		}
		$cutoff = gmdate('Y-m-d', $now - (self::KEEP_DAYS * 86400));
		foreach (glob($this->dir . '/mcp-*.log') ?: [] as $file) {
			if (preg_match('~/mcp-(\d{4}-\d{2}-\d{2})\.log$~', str_replace('\\', '/', $file), $m) && $m[1] < $cutoff) {
				@unlink($file);
			}
		}
	}

	/** One request is one line: newlines in SQL would otherwise make a single call look like
	* several, which is exactly the wrong thing for a log somebody is counting.
	*/
	private function oneLine(string $detail): string {
		$flat = trim(preg_replace('~\s+~', ' ', $detail) ?? '');
		return strlen($flat) > self::MAX_DETAIL ? substr($flat, 0, self::MAX_DETAIL) . '…' : $flat;
	}
}
