<?php
declare(strict_types=1);
namespace Desktop;

/** Tracy bar panel that prints the persistent config file — its path and current values —
* so the stored preferences are one click away while debugging, instead of a hunt through
* the data dir for config.json.
*
* Its own file, required only from debug() under -debug: declaring a class that implements
* a Tracy interface would otherwise autoload Tracy on every production request too, and
* nothing outside -debug has any use for the bar.
*/
class ConfigPanel implements \Tracy\IBarPanel {
	private Config $config;

	function __construct(Config $config) {
		$this->config = $config;
	}

	/** The tab in the debug bar: a gear and how many keys are stored. */
	function getTab(): string {
		return '<span title="Adminer Desktop settings">&#9881; ' . count($this->config->all()) . '</span>';
	}

	/** The panel: where the file lives, then a row per stored value. */
	function getPanel(): string {
		$path = $this->config->path();
		$where = $path === null
			? '<em>no durable data dir (ADMINER_DESKTOP_DATA unset)</em>'
			: htmlspecialchars($path) . (is_file($path) ? '' : ' <em>(not written yet)</em>');

		$rows = '';
		foreach ($this->config->all() as $key => $value) {
			$rows .= '<tr><th>' . htmlspecialchars((string) $key) . '</th><td>'
				. htmlspecialchars((string) json_encode($value)) . '</td></tr>';
		}
		if ($rows === '') {
			$rows = '<tr><td colspan="2"><em>nothing stored yet</em></td></tr>';
		}

		return '<h1>Settings</h1><div class="tracy-inner">'
			. '<p>' . $where . '</p>'
			. '<table>' . $rows . '</table></div>';
	}
}
