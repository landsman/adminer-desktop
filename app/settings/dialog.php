<?php
declare(strict_types=1);
namespace Desktop;

/** The settings dialog itself: the trigger, the tab shell and the actions row.
*
* It owns no settings of its own — the panels come from Theme and PluginList, and this
* only decides where they sit and what the buttons do. The markup lives in dialog.latte;
* what stays here is the behaviour behind the two buttons.
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
		latte()->render(__DIR__ . "/dialog.latte", [
			"desktop" => $this->desktop,
			"theme" => $this->theme,
			"plugins" => $this->plugins,
			"writable" => $this->plugins->writable(),
			"openScript" => $this->openScript(),
			"closeScript" => $this->closeScript(),
		]);
	}

	/** The gear opens the dialog.
	* Adminer sets a CSP nonce on its scripts, so behaviour is attached via its own
	* script()/qsl() helpers; an inline onclick attribute would be blocked. qsl() binds the
	* last matching element in the whole document, which is why the template prints each
	* script straight after its button. The JS stays in PHP because it is behaviour, and
	* because it needs adminer's js_escape() around a translated string.
	*/
	private function openScript(): string {
		return \Adminer\script("qsl('button').onclick = function () { qs('#desktop-settings').showModal(); };");
	}

	/** Cancel closes the dialog, after asking about edits that would be thrown away.
	* Same rule the stylesheet highlights rows by: defaultChecked is the attribute as
	* rendered, checked is what it is now. Radios only count when turned on, since choosing
	* a design necessarily turns the previous one off.
	* reset() before closing, or the discarded edits are still sitting there next time the
	* dialog opens, looking like they were kept.
	*/
	private function closeScript(): string {
		return \Adminer\script("qsl('button').onclick = function () {
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
	}
}
