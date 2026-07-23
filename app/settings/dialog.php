<?php
declare(strict_types=1);
namespace Desktop;

/** The settings dialog itself: the trigger, the tab shell and the actions row.
*
* It owns no settings of its own — the panels come from Theme and PluginList, and this
* only decides where they sit and what the buttons do.
*/
class Dialog {
	/** @var \AdminerDesktop */ private $desktop;
	/** @var Theme */ private $theme;
	/** @var PluginList */ private $plugins;

	function __construct(\AdminerDesktop $desktop, Theme $theme, PluginList $plugins) {
		$this->desktop = $desktop;
		$this->theme = $theme;
		$this->plugins = $plugins;
	}

	function render(): void {
		$writable = $this->plugins->writable();

		// <dialog> rather than a hand-rolled overlay: it brings the backdrop, focus
		// trapping, top-layer stacking and escape-to-close with it, and needs no library.
		echo "<button type='button' id='desktop-gear' title='" . \Adminer\h($this->desktop->t('Settings')) . "'>&#9881;</button>";
		// Adminer sets a CSP nonce on its scripts, so behaviour is attached via its own
		// script()/qsl() helpers; an inline onclick attribute would be blocked.
		echo \Adminer\script("qsl('button').onclick = function () { qs('#desktop-settings').showModal(); };");

		echo "<dialog id='desktop-settings'>\n";
		echo "<form action='' method='post'>\n";

		// Tabs are hidden radios plus :has() in the stylesheet: no JS, and a checked radio
		// is also the state, so nothing has to be restored when the dialog reopens.
		echo "<div id='desktop-tabs'>\n";
		echo "<input type='radio' name='desktop_tab' id='desktop-tab-themes' checked>"
			. "<label for='desktop-tab-themes'>" . \Adminer\h($this->desktop->t('Theme')) . "</label>\n";
		echo "<input type='radio' name='desktop_tab' id='desktop-tab-plugins'>"
			. "<label for='desktop-tab-plugins'>" . \Adminer\h($this->desktop->t('Plugins')) . "</label>\n";
		echo "</div>\n";

		echo "<div id='desktop-panels'>\n";
		echo "<div id='desktop-panel-themes'>\n";
		$this->theme->panel();
		echo "</div>\n<div id='desktop-panel-plugins'>\n";
		$this->plugins->panel();
		echo "</div>\n</div>\n";

		echo \Adminer\input_hidden("desktop_settings", 1);
		echo \Adminer\input_token();

		// Cancel first, primary action last: that is the order every mac dialog uses, and
		// muscle memory puts the confirm button in the bottom right corner.
		echo "<div id='desktop-actions'>";
		echo "<button type='button' id='desktop-close'>" . \Adminer\h($this->desktop->t('Cancel')) . "</button>";
		// Same rule the stylesheet highlights rows by: defaultChecked is the attribute as
		// rendered, checked is what it is now. Radios only count when turned on, since
		// choosing a design necessarily turns the previous one off.
		// reset() before closing, or the discarded edits are still sitting there next time
		// the dialog opens, looking like they were kept.
		echo \Adminer\script("qsl('button').onclick = function () {
	var n = 0;
	for (var input of qsa('#desktop-panels input')) {
		if (input.type == 'checkbox' ? input.checked != input.defaultChecked : input.checked && !input.defaultChecked) {
			n++;
		}
	}
	for (var select of qsa('#desktop-panels select')) {
		if (!select.options[select.selectedIndex].defaultSelected) {
			n++;
		}
	}
	if (!n || confirm('" . \Adminer\js_escape($this->desktop->t('Unsaved changes: {n}. Close anyway?')) . "'.replace('{n}', n))) {
		qs('#desktop-settings').close();
		this.form.reset();
	}
};");
		echo "<button type='submit' id='desktop-save'" . ($writable ? "" : " disabled") . ">"
			. \Adminer\h($this->desktop->t('Save')) . "</button>\n";
		echo "</div>\n</form>\n</dialog>\n";
	}
}
