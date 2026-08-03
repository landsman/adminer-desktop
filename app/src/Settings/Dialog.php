<?php
declare(strict_types=1);

namespace Desktop\Settings;

use Desktop\Latte;
use Desktop\Mcp\Panel as McpPanel;
use Desktop\Settings\Plugins\PluginList;
use Desktop\Settings\Theme\Theme;

/** The settings dialog itself: the trigger, the tab shell and the actions row.
*
* It owns no settings of its own — the panels come from Theme and PluginList, and this
* only decides where they sit. The markup is settings-dialog.latte and the behaviour is
* src/Assets/javascript/settings-dialog.js, so what is left here is handing one the other's
* translated strings.
*/
class Dialog {
	private \AdminerDesktop $desktop;
	private Theme $theme;
	private PluginList $plugins;
	private McpPanel $mcp;

	function __construct(\AdminerDesktop $desktop, Theme $theme, PluginList $plugins, McpPanel $mcp) {
		$this->desktop = $desktop;
		$this->theme = $theme;
		$this->plugins = $plugins;
		$this->mcp = $mcp;
	}

	function render(): void {
		Latte::engine()->render(__DIR__ . "/settings-dialog.latte", [
			"desktop" => $this->desktop,
			"theme" => $this->theme,
			"plugins" => $this->plugins,
			"mcp" => $this->mcp,
			// {n}, not %d: lang() runs the string through sprintf, which would replace %d
			// with 0 before the browser ever saw it. The script fills it in.
			"unsaved" => $this->desktop->t('settings.unsaved'),
		]);
	}
}
