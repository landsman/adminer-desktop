<?php
declare(strict_types=1);

namespace Desktop\Debug;

use Tracy\IBarPanel;

/** Tracy bar panel with what the app uses of the machine. For now its memory: this request's
* PHP heap, and the resident size of the frankenphp server and of the launcher that started it.
*
* Read when the bar renders, so it is current as of each page load or AJAX bar, not a live
* graph. The WebKit processes are not in it: they are XPC children of launchd, so nothing
* ties them to this app's pid — Safari's Web Inspector, Timelines → Memory, has the page's.
*
* Built only in Tracy::enable(), under -debug.
*/
class ResourcesPanel implements IBarPanel {
	/** The tab: what the server and the launcher hold together, or ? when either is unknown —
	* a sum missing one of them would read as the whole.
	*/
	function getTab(): string {
		$readings = $this->processes();
		$total = in_array(null, $readings, true) ? '?' : self::size(array_sum($readings));
		return '<span title="Memory: frankenphp + launcher, resident">&#128190; ' . $total . '</span>';
	}

	function getPanel(): string {
		$rows = '';
		foreach ($this->processes() as $name => $rss) {
			$rows .= '<tr><th>' . htmlspecialchars($name) . '</th><td>' . ($rss === null ? '<em>unknown</em>' : self::size($rss)) . '</td></tr>';
		}
		$rows .= '<tr><th>PHP heap, this request</th><td>' . self::size(memory_get_usage()) . '</td></tr>'
			. '<tr><th>PHP heap, peak</th><td>' . self::size(memory_get_peak_usage()) . '</td></tr>';
		return '<h1>Resources</h1><div class="tracy-inner"><table>' . $rows . '</table></div>';
	}

	/** Resident size in bytes per process, null when ps could not tell.
	* @return array<string, ?int>
	*/
	private function processes(): array {
		$server = (int) getmypid();
		// ponytail: Windows has neither posix_getppid() nor ps, so both read unknown there;
		// tasklist /FI "PID eq n" gives the server's working set if that is ever wanted.
		$launcher = function_exists('posix_getppid') ? posix_getppid() : null;
		return [
			"frankenphp (pid $server)" => self::rss($server),
			'launcher (pid ' . ($launcher ?? '?') . ')' => $launcher === null ? null : self::rss($launcher),
		];
	}

	private static function rss(int $pid): ?int {
		if (PHP_OS_FAMILY === 'Windows') {
			return null;
		}
		$kb = trim((string) shell_exec('ps -o rss= -p ' . $pid));
		return ctype_digit($kb) ? (int) $kb * 1024 : null;
	}

	private static function size(int $bytes): string {
		return number_format($bytes / 1048576, 1) . ' MB';
	}
}
