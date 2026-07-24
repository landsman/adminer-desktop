<?php
declare(strict_types=1);
namespace Desktop;

/** The plugin half of the settings dialog: what is shipped, what is enabled, and the
* panel that toggles them.
*
* Named PluginList rather than Plugins to stay clear of both adminer's own Plugins class
* and its rule of instantiating anything called Adminer* as a plugin.
*/
class PluginList {
	/** @var \AdminerDesktop */ private $desktop;

	function __construct(\AdminerDesktop $desktop) {
		$this->desktop = $desktop;
	}

	/** Get shipped plugins, name => path. The enabled ones are whatever is symlinked into
	* adminer-plugins/, so the filesystem is the only state there is — which means dragging
	* a downloaded plugin into that folder behaves exactly like ticking a box here.
	* @return array<string, string>
	*/
	function available(): array {
		$return = array();
		// Top level only: available/drivers/ are database drivers, which need a
		// server we cannot assume exists, not a checkbox.
		foreach (glob(__DIR__ . "/available/*.php") as $filename) {
			$return[basename($filename, ".php")] = $filename;
		}
		ksort($return);
		return $return;
	}

	/** Get each plugin's one-line description, in the interface language where it has one.
	*
	* Every shipped plugin carries its own translations, so this needs no network and
	* cannot go stale against the bundled version — all 51 have Czech, and most have
	* German, Polish, Croatian and Japanese.
	*
	* The files are included but nothing is instantiated: reflection reads the default
	* value of $translations off the class. Including is safe here because adminer builds
	* its plugin list in Plugins::__construct, which has long since run by the time a page
	* renders, so a class declared now is not picked up and enabled.
	* @return array<string, string>
	*/
	function descriptions(): array {
		$return = array();
		foreach ($this->available() as $name => $filename) {
			$before = get_declared_classes();
			@include_once $filename;
			$description = "";
			foreach (array_diff(get_declared_classes(), $before) as $class) {
				$defaults = (new \ReflectionClass($class))->getDefaultProperties();
				$translations = (isset($defaults["translations"]) ? (array) $defaults["translations"] : array());
				$description = (string) ($translations[\Adminer\LANG][""] ?? "");
			}
			if ($description === "") {
				// English fallback: the opening doc-comment, which every plugin has even
				// when it has no translation for this language.
				$head = (string) file_get_contents($filename, false, null, 0, 400);
				$description = (preg_match('~/\*\*\s*\**\s*(.+)~', $head, $match) ? trim($match[1]) : "");
			}
			$return[$name] = $description;
		}
		return $return;
	}

	function link(string $name): string {
		return $this->desktop->dir() . "/adminer-plugins/$name.php";
	}

	/** Is this enabled plugin one we put there, and therefore ours to remove?
	* A symlink always is. A copy counts only while it still matches what we ship —
	* so a .php the user dropped in by hand is never deleted by a checkbox, even if it
	* happens to share a name with a bundled plugin.
	*/
	function isOurs(string $link, string $filename): bool {
		if (is_link($link)) {
			return true;
		}
		return file_exists($link) && file_get_contents($link) === file_get_contents($filename);
	}

	/** Render the plugin panel. The markup is plugins-panel.latte. */
	function panel(): void {
		$available = $this->available();
		if (!$available) {
			return;
		}
		$descriptions = $this->descriptions();
		$plugins = [];
		foreach ($available as $name => $filename) {
			$plugins[$name] = [
				// A plugin name is a filename, and an id has to be usable in a selector.
				"id" => "desktop-plugin-" . preg_replace('~[^\w-]~', "-", $name),
				// The filesystem is the state: enabled means it is in adminer-plugins/.
				"enabled" => file_exists($this->link($name)),
				"description" => $descriptions[$name],
			];
		}
		latte()->render(__DIR__ . "/plugins-panel.latte", [
			"desktop" => $this->desktop,
			"plugins" => $plugins,
			"writable" => $this->writable(),
		]);
	}

	/** Can we enable and disable at all? The bundle is writable on macOS, but a copy in a
	* read-only location would leave the checkboxes lying about what they do.
	*/
	function writable(): bool {
		return is_writable(dirname($this->link("x")));
	}

	/** Enable exactly the plugins posted, disable the rest. */
	function apply(): void {
		// Whitelist by construction -- we iterate what we ship and only look the POSTed
		// names up in it, so nothing user-supplied ever reaches a filesystem path.
		// ?? []: unticking the last plugin posts no plugins[] at all, which is the case that
		// has to disable them, not warn.
		$wanted = array_flip((array) ($_POST["plugins"] ?? []));
		foreach ($this->available() as $name => $filename) {
			$link = $this->link($name);
			if (isset($wanted[$name])) {
				if (!file_exists($link)) {
					// Relative target, so it survives app/ being moved into a .app bundle.
					// Windows only allows symlinks with elevated rights or developer mode
					// on, so fall back to a copy there rather than failing silently.
					@symlink("../settings/plugins/available/$name.php", $link) || @copy($filename, $link);
				}
			} elseif ($this->isOurs($link, $filename)) {
				@unlink($link);
			}
		}
	}
}
