<?php
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
		$return = array("" => $this->desktop->t('(built-in)'));
		foreach (glob($this->desktop->dir() . "/designs/*/*.css") as $filename) {
			$dir = basename(dirname($filename));
			$path = "designs/$dir/" . basename($filename);
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

	/** The light/dark stylesheet map adminer's css() hook expects. */
	function cssMap() {
		// PHP cannot know the OS theme — only a CSS media query can. So "auto" means
		// handing adminer both stylesheets and letting the browser choose: when css()
		// returns a light one and a dark one, design.inc.php:53 tags each with the
		// matching prefers-color-scheme query.
		$return = array();
		foreach (array("light", "dark") as $mode) {
			$design = $_SESSION["design_$mode"];
			// array_key_exists, not truthiness: guards against a stale session pointing at
			// a design that a later adminer release no longer ships.
			if ($design && array_key_exists($design, $this->designs($mode))) {
				$return[$design] = $mode;
			}
		}
		// null, not an empty array: css() short-circuits on the first non-null, and we
		// want adminer's own built-in theme (which already auto-switches) when neither
		// side is set.
		return $return ?: null;
	}

	/** Render the theme panel: one table per light/dark side, previews and all. */
	function panel(): void {
		echo "<p class='message'>" . \Adminer\h($this->desktop->t('Pick one of each; the system setting decides which applies.')) . "\n";
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
				echo "<tr><td style='white-space: nowrap'>"
					. "<input type='radio' name='design_$mode' value='" . \Adminer\h($path) . "' id='$id'$checked>"
					. "<label for='$id' style='display: inline-block'> " . \Adminer\h($design) . "</label>"
					. "<td><label for='$id'>";
				if ($path) {
					// loading=lazy so opening the dialog does not fire 26 requests at once;
					// the endpoint serves a placeholder rather than failing when offline.
					echo "<img src='settings/theme/screenshot.php?design=" . urlencode(basename(dirname($path)))
						. "' alt='' loading='lazy' width='160' height='100'>";
				}
				echo "</label>\n";
			}
			echo "</tbody>\n</table>\n";
		}
	}

	/** Store the chosen designs. */
	function apply(): void {
		foreach (array("light", "dark") as $mode) {
			$_SESSION["design_$mode"] = $_POST["design_$mode"];
		}
	}
}
