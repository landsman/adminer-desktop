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
* Built only in Debug::enable(), under -debug.
*/
class ResourcesPanel implements IBarPanel {
	/** The tab: what the server and the launcher hold together. */
	function getTab(): string {
		$total = array_sum(array_filter($this->processes()));
		return '<span title="Memory: frankenphp + launcher, resident">&#128190; ' . self::size($total) . '</span>';
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
		return [
			'frankenphp (pid ' . getmypid() . ')' => self::rss((int) getmypid()),
			'launcher (pid ' . posix_getppid() . ')' => self::rss(posix_getppid()),
		];
	}

	private static function rss(int $pid): ?int {
		$kb = trim((string) shell_exec('ps -o rss= -p ' . $pid));
		return ctype_digit($kb) ? (int) $kb * 1024 : null;
	}

	private static function size(int $bytes): string {
		return number_format($bytes / 1048576, 1) . ' MB';
	}
}
