<?php
declare(strict_types=1);
namespace Desktop;

/** The settings dialog itself: the trigger, the tab shell and the actions row.
*
* It owns no settings of its own — the panels come from Theme and PluginList, and this
* only decides where they sit. The markup is settings-dialog.latte and the behaviour is
* desktop/javascript/settings-dialog.js, so what is left here is handing one the other's
* translated strings.
*/
class Dialog {
	private \AdminerDesktop $desktop;
	private Theme $theme;
	private PluginList $plugins;

	function __construct(\AdminerDesktop $desktop, Theme $theme, PluginList $plugins) {
		$this->desktop = $desktop;
		$this->theme = $theme;
		$this->plugins = $plugins;
	}

	function render(): void {
		latte()->render(__DIR__ . "/settings-dialog.latte", [
			"desktop" => $this->desktop,
			"theme" => $this->theme,
			"plugins" => $this->plugins,
			"writable" => $this->plugins->writable(),
			// {n}, not %d: lang() runs the string through sprintf, which would replace %d
			// with 0 before the browser ever saw it. The script fills it in.
			"unsaved" => $this->desktop->t('Unsaved changes: {n}. Close anyway?'),
		]);
	}
}
