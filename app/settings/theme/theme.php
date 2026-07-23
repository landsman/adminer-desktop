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

	/** Render the theme panel: the density picker, then one table per light/dark side. */
	function panel(): void {
		echo "<p class='message'>" . \Adminer\h($this->desktop->t('Adminer Desktop follows the system light and dark automatically. Override either side with a design below.')) . "\n";

		// Row density applies to the Adminer Desktop theme (it drives our --ad-row-*); a
		// gallery design brings its own spacing, so this has no effect while one is chosen.
		$density = $_SESSION["density"] ?? "cozy";
		echo "<h4>" . \Adminer\h($this->desktop->t('Row density')) . "</h4>\n<p>";
		// t() takes literal strings (it runs them through lang()), so spell each label out
		// rather than translating a loop variable.
		foreach (array(
			"compact" => $this->desktop->t('Compact'),
			"cozy" => $this->desktop->t('Cozy'),
			"comfortable" => $this->desktop->t('Comfortable'),
		) as $value => $label) {
			$id = "desktop-density-$value";
			$checked = ($density == $value ? " checked" : "");
			echo "<label for='$id' style='margin-right: 1.2em; white-space: nowrap'>"
				. "<input type='radio' name='density' value='$value' id='$id'$checked> "
				. \Adminer\h($label) . "</label>";
		}
		echo "\n";

		// Scaling zooms the whole UI. Like density it only affects the Adminer Desktop theme.
		$scaling = $_SESSION["scaling"] ?? "100";
		echo "<h4>" . \Adminer\h($this->desktop->t('Scaling')) . "</h4>\n<p>";
		echo "<select name='scaling'>";
		foreach (self::SCALINGS as $value) {
			$selected = ($scaling == $value ? " selected" : "");
			echo "<option value='$value'$selected>$value%</option>";
		}
		echo "</select>\n";

		// A design is either light or dark -- none upstream ships both -- so it belongs to
		// exactly one of these tables, and the radio group it sits in is what makes it the
		// light choice or the dark one.
		foreach (array("light" => $this->desktop->t('Light'), "dark" => $this->desktop->t('Dark')) as $mode => $label) {
			echo "<h4>" . \Adminer\h($label) . "</h4>\n";
			// class=odds is adminer's own zebra striping, and it is overridden in dark.css,
			// so the rows follow whichever design is active instead of us picking colours.
			echo "<table class='odds'>\n";
			echo "<thead><tr><th>" . \Adminer\h($this->desktop->t('Design')) . "<th>" . \Adminer\h($this->desktop->t('Preview')) . "</thead>\n";
			echo "<tbody>\n";
			$i = 0;
			foreach ($this->designs($mode) as $path => $design) {
				$id = "desktop-design-$mode-" . $i++;
				$checked = ($_SESSION["design_$mode"] == $path ? " checked" : "");
				// Every cell's content is a <label for> the row's input, so clicking the name
				// or the preview selects it, not just the radio itself.
				// The whole first cell is one <label> around the radio and the name, so
				// clicking anywhere in it selects the design; the second cell wraps the
				// preview the same way. #desktop-panels td label is display:block.
				echo "<tr><td style='white-space: nowrap'>"
					. "<label for='$id'>"
					. "<input type='radio' name='design_$mode' value='" . \Adminer\h($path) . "' id='$id'$checked> "
					. \Adminer\h($design)
					. "</label>"
					. "<td><label for='$id'>";
				if ($path) {
					// loading=lazy so opening the dialog does not fire 26 requests at once;
					// the endpoint serves a placeholder rather than failing when offline.
					echo "<img src='settings/theme/screenshot.php?design=" . urlencode(basename(dirname($path)))
						. "' alt='' loading='lazy' width='160' height='100'>";
				} else {
					// Our own default has no adminer.org screenshot; the placeholder gives its
					// row the same height as the gallery rows.
					echo "<img src='settings/theme/placeholder.svg' alt='' width='160' height='100'>";
				}
				echo "</label>\n";
			}
			echo "</tbody>\n</table>\n";
		}
	}

	/** Store the chosen designs and density. */
	function apply(): void {
		foreach (array("light", "dark") as $mode) {
			$_SESSION["design_$mode"] = $_POST["design_$mode"];
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
