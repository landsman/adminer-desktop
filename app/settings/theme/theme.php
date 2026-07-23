<?php
declare(strict_types=1);
namespace Desktop;

/** The theme half of the settings dialog: which designs exist, which are chosen, and
* the panel that lets you change that.
*
* Not named Adminer* and not extending Adminer\Plugin on purpose: adminer instantiates
* every declared class whose name starts with "Adminer" and registers it as a plugin
* (include/plugins.inc.php:33), so a helper called AdminerTheme would quietly become one.
*/
class Theme {
	/** @var \AdminerDesktop */ private $desktop;

	function __construct(\AdminerDesktop $desktop) {
		$this->desktop = $desktop;
	}

	/** Get shipped designs for one side of the light/dark split, path => label
	* @param string $mode "light" or "dark"
	* @return array<string, string>
	*/
	function designs(string $mode): array {
		// The empty option is our own theme, which is the default for this side rather than
		// Adminer's raw look. Picking a gallery design below overrides it.
		$return = array("" => $this->desktop->t('Adminer Desktop'));
		foreach (glob(__DIR__ . "/designs/*/*.css") as $filename) {
			$dir = basename(dirname($filename));
			if ($dir === "adminer-desktop") {
				continue; // our theme is the default, not one gallery entry among the rest
			}
			$path = "settings/theme/designs/$dir/" . basename($filename);
			// Match -dark anywhere in the path, not just the filename, which is what
			// upstream plugins/designs.php:30 does. rmsoft_blue-dark is the case that
			// proves it: the folder is marked dark but its file is a plain adminer.css,
			// so matching the basename alone lands a dark theme in the light list.
			$is_dark = (bool) preg_match('~-dark~', $path);
			if ($is_dark == ($mode == "dark")) {
				$return[$path] = $dir;
			}
		}
		asort($return);
		return $return;
	}

	/** The light/dark stylesheet map adminer's css() hook expects.
	*
	* PHP cannot know the OS theme — only a CSS media query can — so a light and a dark
	* entry are handed over together and design.inc.php:53 tags each with the matching
	* prefers-color-scheme query. Our own theme is one file carrying both schemes, so when
	* it covers both sides it is handed over with an empty value: no media query, and its
	* internal @media does the switching. Splitting it across two media queries instead
	* would pin each half to one scheme and disable the other.
	*/
	function cssMap() {
		$self = "settings/theme/designs/adminer-desktop/adminer.css";
		$sides = array();
		foreach (array("light", "dark") as $mode) {
			$design = $_SESSION["design_$mode"] ?? "";
			// array_key_exists, not truthiness: a stale session pointing at a design a later
			// adminer release no longer ships falls back to ours rather than to nothing.
			$sides[$mode] = ($design && array_key_exists($design, $this->designs($mode))) ? $design : $self;
		}
		if ($sides["light"] === $self && $sides["dark"] === $self) {
			return array($self => ""); // the out-of-the-box case: one self-switching sheet
		}
		// A gallery design overrides the side it was chosen for; the other side stays ours,
		// tagged with its scheme so only that half of our file applies.
		$return = array();
		foreach ($sides as $mode => $path) {
			$return[$path] = $mode;
		}
		return $return;
	}

	/** Render the theme panel: the preferences, then one table per light/dark side.
	* The markup is panel.latte; what is prepared here is what a template cannot say —
	* t() takes literal strings (it runs them through lang()), so every label has to be
	* spelled out rather than translated from a loop variable.
	*/
	function panel(): void {
		latte()->render(__DIR__ . "/panel.latte", [
			"desktop" => $this->desktop,
			"densities" => [
				"compact" => $this->desktop->t('Compact'),
				"cozy" => $this->desktop->t('Cozy'),
				"comfortable" => $this->desktop->t('Comfortable'),
			],
			"density" => (string) ($_SESSION["density"] ?? "cozy"),
			"scalings" => self::SCALINGS,
			"scaling" => (string) ($_SESSION["scaling"] ?? "100"),
			// ?? "", like cssMap() above: nothing is stored until a design is picked, and the
			// panel is drawn on every page — that would be a warning per row per load.
			"sides" => [
				[
					"mode" => "light",
					"label" => $this->desktop->t('Light'),
					"designs" => $this->designs("light"),
					"chosen" => (string) ($_SESSION["design_light"] ?? ""),
				],
				[
					"mode" => "dark",
					"label" => $this->desktop->t('Dark'),
					"designs" => $this->designs("dark"),
					"chosen" => (string) ($_SESSION["design_dark"] ?? ""),
				],
			],
		]);
	}

	/** Store the chosen designs and density. */
	function apply(): void {
		foreach (array("light", "dark") as $mode) {
			$_SESSION["design_$mode"] = $_POST["design_$mode"] ?? "";
		}
		// Whitelisted: these values are echoed into body classes, so never store raw input.
		$density = $_POST["density"] ?? "cozy";
		$_SESSION["density"] = in_array($density, self::DENSITIES, true) ? $density : "cozy";
		$scaling = $_POST["scaling"] ?? "100";
		$_SESSION["scaling"] = in_array($scaling, self::SCALINGS, true) ? $scaling : "100";
	}

	/** The density and scaling classes for <body>, added next to the OS class in
	* AdminerDesktop. */
	function bodyClass(): void {
		$density = $_SESSION["density"] ?? "cozy";
		echo " density-" . (in_array($density, self::DENSITIES, true) ? $density : "cozy");
		$scaling = $_SESSION["scaling"] ?? "100";
		echo " scale-" . (in_array($scaling, self::SCALINGS, true) ? $scaling : "100");
	}

	/** @var list<string> */ private const DENSITIES = array("compact", "cozy", "comfortable");
	/** @var list<string> */ private const SCALINGS = array("100", "125", "150", "175", "200");
}
