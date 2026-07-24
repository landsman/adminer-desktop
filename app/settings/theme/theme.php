<?php
declare(strict_types=1);
namespace Desktop;

require_once __DIR__ . "/mode.php";

/** The theme half of the settings dialog: which designs exist, which are chosen, and
* the panel that lets you change that.
*
* Not named Adminer* and not extending Adminer\Plugin on purpose: adminer instantiates
* every declared class whose name starts with "Adminer" and registers it as a plugin
* (include/plugins.inc.php:33), so a helper called AdminerTheme would quietly become one.
*/
class Theme {
	private \AdminerDesktop $desktop;

	function __construct(\AdminerDesktop $desktop) {
		$this->desktop = $desktop;
	}

	/** Get shipped designs for one side of the light/dark split, path => label
	* @return array<string, string>
	*/
	function designs(Mode $mode): array {
		// The empty option is our own theme, which is the default for this side rather than
		// Adminer's raw look. Picking a gallery design below overrides it.
		$return = ["" => $this->desktop->t('Adminer Desktop')];
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
			if ($is_dark === ($mode === Mode::Dark)) {
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
	* @return array<string,string>
	*/
	function cssMap(): array {
		$self = "settings/theme/designs/adminer-desktop/adminer.css";
		$sides = [];
		foreach (Mode::cases() as $mode) {
			$design = $_SESSION["design_" . $mode->value] ?? "";
			// array_key_exists, not truthiness: a stale session pointing at a design a later
			// adminer release no longer ships falls back to ours rather than to nothing.
			$sides[$mode->value] = ($design && array_key_exists($design, $this->designs($mode))) ? $design : $self;
		}
		// An appearance override pins the page to one scheme: hand adminer only that side's
		// design, tagged with its scheme. design.inc.php then sets <meta name="color-scheme">
		// to it, which pins our light-dark() tokens to that side and (for dark) loads
		// adminer's own dark.css for the JUSH palette — so the choice themes everything
		// without the OS. "auto" is not a Mode, so tryFrom() returns null and it falls through
		// to the OS-driven map below.
		$appearance = $_SESSION["appearance"] ?? "auto";
		$pinned = Mode::tryFrom(is_string($appearance) ? $appearance : "");
		if ($pinned !== null) {
			return [$sides[$pinned->value] => $pinned->value];
		}
		if ($sides[Mode::Light->value] === $self && $sides[Mode::Dark->value] === $self) {
			return [$self => ""]; // the out-of-the-box case: one self-switching sheet
		}
		// A gallery design overrides the side it was chosen for; the other side stays ours,
		// tagged with its scheme so only that half of our file applies.
		$return = [];
		foreach ($sides as $mode => $path) {
			$return[$path] = $mode;
		}
		return $return;
	}

	/** Render the theme panel: the preferences, then one table per light/dark side.
	* The markup is theme-panel.latte; what is prepared here is what a template cannot say —
	* t() takes literal strings (it runs them through lang()), so every label has to be
	* spelled out rather than translated from a loop variable.
	*/
	function panel(): void {
		latte()->render(__DIR__ . "/theme-panel.latte", [
			"desktop" => $this->desktop,
			// t() takes literal strings (it runs them through lang()), so the labels are
			// spelled out here rather than translated from a loop variable.
			"appearances" => [
				"auto" => $this->desktop->t('Sync with OS'),
				"light" => $this->desktop->t('Light'),
				"dark" => $this->desktop->t('Dark'),
			],
			"appearance" => (string) ($_SESSION["appearance"] ?? "auto"),
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
			"sides" => $this->sides(),
		]);
	}

	/** One entry per light/dark side for the panel: its field name, label, designs and the
	* one chosen. t() takes literal strings (it runs them through lang()), so the label is a
	* match on the case rather than a translation of $mode->value.
	* @return list<array{mode:string, label:string, designs:array<string,string>, chosen:string}>
	*/
	private function sides(): array {
		$sides = [];
		foreach (Mode::cases() as $mode) {
			$sides[] = [
				"mode" => $mode->value,
				"label" => $mode === Mode::Light ? $this->desktop->t('Light') : $this->desktop->t('Dark'),
				"designs" => $this->designs($mode),
				"chosen" => (string) ($_SESSION["design_" . $mode->value] ?? ""),
			];
		}
		return $sides;
	}

	/** Store the chosen designs, appearance and density. */
	function apply(): void {
		foreach (Mode::cases() as $mode) {
			$key = "design_" . $mode->value;
			$_SESSION[$key] = $_POST[$key] ?? "";
		}
		// Whitelisted: these values are echoed into body classes, so never store raw input.
		$appearance = $_POST["appearance"] ?? "auto";
		$_SESSION["appearance"] = in_array($appearance, self::APPEARANCES, true) ? $appearance : "auto";
		$density = $_POST["density"] ?? "cozy";
		$_SESSION["density"] = in_array($density, self::DENSITIES, true) ? $density : "cozy";
		$scaling = $_POST["scaling"] ?? "100";
		$_SESSION["scaling"] = in_array($scaling, self::SCALINGS, true) ? $scaling : "100";
	}

	/** The appearance, density and scaling classes for <body>, added next to the OS class in
	* AdminerDesktop. The appearance class is only read by the dark icon invert in base.css —
	* the colours themselves ride on adminer's color-scheme meta (cssMap), not this class. */
	function bodyClass(): void {
		$appearance = $_SESSION["appearance"] ?? "auto";
		echo " theme-" . (in_array($appearance, self::APPEARANCES, true) ? $appearance : "auto");
		$density = $_SESSION["density"] ?? "cozy";
		echo " density-" . (in_array($density, self::DENSITIES, true) ? $density : "cozy");
		$scaling = $_SESSION["scaling"] ?? "100";
		echo " scale-" . (in_array($scaling, self::SCALINGS, true) ? $scaling : "100");
	}

	/** @var list<string> */ private const APPEARANCES = ["auto", "light", "dark"];
	/** @var list<string> */ private const DENSITIES = ["compact", "cozy", "comfortable"];
	/** @var list<string> */ private const SCALINGS = ["100", "125", "150", "175", "200"];
}
