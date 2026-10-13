<?php
declare(strict_types=1);

namespace Desktop\Settings;

use Desktop\Latte;
use Desktop\Mcp\Panel as McpPanel;
use Desktop\Settings\Plugins\PluginList;
use Desktop\Settings\Theme\Theme;
use Desktop\Shortcuts\Shortcuts;

/** The settings dialog itself: the trigger, the tab shell and the actions row.
*
* It owns no settings of its own — the panels come from Theme, PluginList, the MCP panel and
* Shortcuts, and this
* only decides where they sit. The markup is settings-dialog.latte and the behaviour is
* src/Assets/javascript/settings-dialog.js.
*/
class Dialog {
	private \AdminerDesktop $desktop;
	private Theme $theme;
	private PluginList $plugins;
	private McpPanel $mcp;
	private Shortcuts $shortcuts;

	function __construct(\AdminerDesktop $desktop, Theme $theme, PluginList $plugins, McpPanel $mcp, Shortcuts $shortcuts) {
		$this->desktop = $desktop;
		$this->theme = $theme;
		$this->plugins = $plugins;
		$this->mcp = $mcp;
		$this->shortcuts = $shortcuts;
	}

	function render(): void {
		Latte::engine()->render(__DIR__ . "/settings-dialog.latte", [
			"desktop" => $this->desktop,
			"theme" => $this->theme,
			"plugins" => $this->plugins,
			"mcp" => $this->mcp,
			"shortcuts" => $this->shortcuts,
		]);
	}
}
