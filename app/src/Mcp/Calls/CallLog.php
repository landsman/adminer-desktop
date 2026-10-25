<?php
declare(strict_types=1);

namespace Desktop\Mcp\Calls;

use Desktop\Env;

/** Reads what the bridge wrote: every mcp-YYYY-MM-DD.log in the launcher's log directory, a JSON
* object per line (launcher/mcpbridge record.go). Only reads — the bridge is the one writer, and
* it keeps the files to two weeks.
*/
class CallLog {
	private ?string $dir;

	function __construct(?string $dir = null) {
		$dir = $dir ?? (Env::Logs->get() ?: null);
		$this->dir = $dir !== null && $dir !== '' ? $dir : null;
	}

	/** Every call, newest first, each without its request (Call::light()): read a line at a time,
	* so two weeks of large requests are never in memory at once.
	*
	* @return list<Call>
	*/
	function all(): array {
		$calls = [];
		foreach ($this->files() as $day => $file) {
			$handle = @fopen($file, 'r');
			for ($i = 1; $handle !== false && ($line = fgets($handle)) !== false; $i++) {
				$call = self::parse(rtrim($line, "\r\n"), "$day:$i");
				if ($call !== null) {
					$calls[] = $call->light();
				}
			}
			if ($handle !== false) {
				fclose($handle);
			}
		}
		// ponytail: sorted by the time each line carries, as a string — ISO times in UTC sort as
		// text. Several bridges append in the order calls finish, not start.
		usort($calls, fn(Call $a, Call $b) => strcmp($b->time, $a->time));
		return $calls;
	}

	/** One call by its id, or null when the file or the line is gone. */
	function find(string $id): ?Call {
		if (!preg_match('~^(\d{4}-\d{2}-\d{2}):(\d+)$~', $id, $m)) {
			return null;
		}
		$file = $this->files()[$m[1]] ?? null;
		$lines = $file !== null ? (array) file($file, FILE_IGNORE_NEW_LINES) : [];
		$line = $lines[(int) $m[2] - 1] ?? null;
		return is_string($line) ? self::parse($line, $id) : null;
	}

	/** One line: the bridge's JSON, or the tab-separated one adminer's endpoint wrote before it —
	* time, method, tool, detail — whose outcome nobody recorded.
	*/
	static function parse(string $line, string $id): ?Call {
		if (trim($line) === '') {
			return null;
		}
		// Objects, not arrays: an empty {} in the request would otherwise be shown as [].
		$json = json_decode($line);
		if (!$json instanceof \stdClass) {
			$fields = explode("\t", $line);
			return new Call($id, $fields[0], CallStatus::Unknown, $fields[1] ?? '', $fields[2] ?? '', detail: $fields[3] ?? '');
		}
		$string = fn(string $key): string => is_string($json->$key ?? null) ? $json->$key : '';
		$request = $json->request ?? null;
		return new Call(
			$id,
			$string('time'),
			CallStatus::tryFrom($string('status')) ?? CallStatus::Unknown,
			$string('method'),
			$string('tool'),
			is_int($json->duration_ms ?? null) ? $json->duration_ms : null,
			$string('connection'),
			$string('connection_name'),
			$string('database'),
			$string('error'),
			is_object($request) || is_array($request) ? $request : null,
			is_string($request) ? $request : '', // a line that was not JSON
		);
	}

	/** @return array<string,string> date => path, newest first */
	private function files(): array {
		$files = [];
		foreach ($this->dir !== null ? glob($this->dir . '/mcp-*.log') ?: [] : [] as $file) {
			if (preg_match('~mcp-(\d{4}-\d{2}-\d{2})\.log$~', $file, $m)) {
				$files[$m[1]] = $file;
			}
		}
		krsort($files);
		return $files;
	}
}
