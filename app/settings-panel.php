<?php
declare(strict_types=1);
namespace Desktop;

/** Tracy bar panel that prints the user's persistent settings file — its path and current
* values — so the stored preferences are one click away while debugging, instead of a hunt
* through the data dir for settings.json.
*
* Its own file, required only from debug() under -debug: declaring a class that implements
* a Tracy interface would otherwise autoload Tracy on every production request too, and
* nothing outside -debug has any use for the bar.
*/
class SettingsPanel implements \Tracy\IBarPanel {
	private UserSettings $settings;

	function __construct(UserSettings $settings) {
		$this->settings = $settings;
	}

	/** The tab in the debug bar: a gear and how many keys are stored. */
	function getTab(): string {
		return '<span title="Adminer Desktop settings">&#9881; ' . count($this->settings->all()) . '</span>';
	}

	/** The panel: where the file lives, then a row per stored value. */
	function getPanel(): string {
		$path = $this->settings->path();
		$where = $path === null
			? '<em>no durable data dir (ADMINER_DESKTOP_DATA unset)</em>'
			: htmlspecialchars($path) . (is_file($path) ? '' : ' <em>(not written yet)</em>');

		$rows = '';
		foreach ($this->settings->all() as $key => $value) {
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
