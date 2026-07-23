<?php

/** Adapt Adminer's defaults to running as a desktop app
* @author adminer-desktop
* @license https://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
*/
class AdminerDesktop extends Adminer\Plugin {
	function loginFormField($name, $heading, $value) {
		// Adminer ships the Server field empty, which means "connect over a Unix socket".
		// That is right for a server deployment and wrong for a desktop one: here the
		// database is nearly always in Docker or remote, and Docker publishes TCP only —
		// it never creates a socket. The stock default fails with
		// `connection to server on socket "/tmp/.s.PGSQL.5432" failed` while the server is
		// answering perfectly well on 127.0.0.1.
		// Only fill in a blank field; never override a server the user has already chosen.
		if ($name == "server" && Adminer\SERVER == "") {
			// Rewrite the value in Adminer's own markup rather than restating it, so this
			// doesn't silently drift when the login form changes upstream.
			return str_replace('value=""', 'value="127.0.0.1"', $value);
		}
		return null; // let adminer handle every other field
	}

	function permanentLogin($create = false) {
		// Adminer already remembers servers and databases you have logged into and offers
		// them for one click on the login page — but only for as long as the key behind
		// "Permanent login" survives, and upstream keeps that key in get_temp_dir(). On
		// macOS that is /var/folders/…/T, which the OS cleans out, so the saved list
		// silently expires with "Master password expired".
		// Same mechanism, same adminer helpers, durable location. The launcher passes the
		// path in, so the per-OS choice stays in one place (Go's os.UserConfigDir) instead
		// of being restated here for macOS, Linux and Windows.
		$dir = getenv("ADMINER_DESKTOP_DATA");
		if (!$dir) {
			return ''; // served outside the app: no durable home, so no permanent login
		}
		$filename = "$dir/adminer.key";
		if (!$create && !file_exists($filename)) {
			return '';
		}
		// 0700 before the file exists: this key decrypts stored database passwords, so it
		// must never be readable by another account on the machine.
		if (!is_dir($dir)) {
			@mkdir($dir, 0700, true);
		}
		$fp = Adminer\file_open_lock($filename);
		if (!$fp) {
			return '';
		}
		$return = stream_get_contents($fp);
		if (!$return) {
			$return = Adminer\rand_string();
			Adminer\file_write_unlock($fp, $return);
			@chmod($filename, 0600);
		} else {
			Adminer\file_unlock($fp);
		}
		return $return;
	}

	/** Get this directory with forward slashes.
	* __DIR__ is backslash-separated on Windows, and glob() treats a backslash as an
	* escape character there, so a pattern built from raw __DIR__ silently matches
	* nothing — no designs, no plugins, and no error to say why. PHP accepts forward
	* slashes on every platform.
	*/
	private function dir(): string {
		return str_replace('\\', '/', __DIR__);
	}

	/** Get shipped designs for one side of the light/dark split, path => label
	* @param string $mode "light" or "dark"
	* @return array<string, string>
	*/
	function designs(string $mode): array {
		$return = array("" => $this->lang('(built-in)'));
		foreach (glob($this->dir() . "/designs/*/*.css") as $filename) {
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

	function css() {
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

	/** Get shipped plugins, name => path. The enabled ones are whatever is symlinked into
	* adminer-plugins/, so the filesystem is the only state there is — which means dragging
	* a downloaded plugin into that folder behaves exactly like ticking a box here.
	* @return array<string, string>
	*/
	private function available(): array {
		$return = array();
		// Top level only: plugins-available/drivers/ are database drivers, which need a
		// server we cannot assume exists, not a checkbox.
		foreach (glob($this->dir() . "/plugins-available/*.php") as $filename) {
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
	private function descriptions(): array {
		$return = array();
		foreach ($this->available() as $name => $filename) {
			$before = get_declared_classes();
			@include_once $filename;
			$description = "";
			foreach (array_diff(get_declared_classes(), $before) as $class) {
				$defaults = (new \ReflectionClass($class))->getDefaultProperties();
				$translations = (isset($defaults["translations"]) ? (array) $defaults["translations"] : array());
				$description = (string) ($translations[Adminer\LANG][""] ?? "");
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

	private function link(string $name): string {
		return $this->dir() . "/adminer-plugins/$name.php";
	}

	/** Is this enabled plugin one we put there, and therefore ours to remove?
	* A symlink always is. A copy counts only while it still matches what we ship —
	* so a .php the user dropped in by hand is never deleted by a checkbox, even if it
	* happens to share a name with a bundled plugin.
	*/
	private function isOurs(string $link, string $filename): bool {
		if (is_link($link)) {
			return true;
		}
		return file_exists($link) && file_get_contents($link) === file_get_contents($filename);
	}

	/** Apply settings posted from the modal. Called from adminer-plugins.php rather than
	* from a hook: it runs after session_start() and before any output, which is what both
	* the session write and the redirect need. afterConnect() would only fire once you are
	* connected, so nothing here would work on the login screen.
	*/
	function handlePost(): void {
		if (!$_POST["desktop_settings"] || !Adminer\verify_token()) {
			return;
		}
		Adminer\restart_session();
		foreach (array("light", "dark") as $mode) {
			$_SESSION["design_$mode"] = $_POST["design_$mode"];
		}
		// Whitelist by construction — we iterate what we ship and only look the POSTed
		// names up in it, so nothing user-supplied ever reaches a filesystem path.
		$wanted = array_flip((array) $_POST["plugins"]);
		foreach ($this->available() as $name => $filename) {
			$link = $this->link($name);
			if (isset($wanted[$name])) {
				if (!file_exists($link)) {
					// Relative target, so it survives app/ being moved into a .app bundle.
					// Windows only allows symlinks with elevated rights or developer mode
					// on, so fall back to a copy there rather than failing silently.
					@symlink("../plugins-available/$name.php", $link) || @copy($filename, $link);
				}
			} elseif ($this->isOurs($link, $filename)) {
				@unlink($link);
			}
		}
		Adminer\redirect($_SERVER["REQUEST_URI"]);
	}

	function head($dark = null) {
		// Everything this plugin adds to adminer's UI is in its own stylesheet rather than
		// inline <style>, so it can be read and diffed as CSS. filemtime busts the cache on
		// edit without a build step.
		// index.css only imports, so its own mtime says nothing about whether the styles
		// changed; the newest of them all is what has to bust the cache.
		$mtime = 0;
		foreach (glob($this->dir() . "/styles/*.css") as $file) {
			$mtime = max($mtime, (int) @filemtime($file));
		}
		echo "<link rel='stylesheet' href='styles/desktop.css?v=$mtime'>\n";
		return null; // let adminer's own head() run; it prints the favicon
	}

	function navigation($missing) {
		$writable = is_writable(dirname($this->link("x")));
		// Lock the page behind the dialog. CSS rather than JS toggling a class: <dialog>
		// can be closed by escape and by form submission as well as by the Cancel button,
		// and a handler hung on the button would miss both — leaving the page unscrollable
		// with nothing on screen to explain why.
		// <dialog> rather than a hand-rolled overlay: it brings the backdrop, focus
		// trapping, top-layer stacking and escape-to-close with it, and needs no library.
		echo "<button type='button' id='desktop-gear' title='" . Adminer\h($this->lang('Settings')) . "'>&#9881;</button>";
		// Adminer sets a CSP nonce on its scripts, so behaviour is attached via its own
		// script()/qsl() helpers; an inline onclick attribute would be blocked.
		echo Adminer\script("qsl('button').onclick = function () { qs('#desktop-settings').showModal(); };");

		// Wide enough for a plugin name and its description side by side, and roomy enough
		// that the next thing added here does not need the size revisited.
		echo "<dialog id='desktop-settings'>\n";
		echo "<form action='' method='post'>\n";

		echo "<div id='desktop-tabs'>\n";
		echo "<input type='radio' name='desktop_tab' id='desktop-tab-plugins' checked>"
			. "<label for='desktop-tab-plugins'>" . Adminer\h($this->lang('Plugins')) . "</label>\n";
		echo "<input type='radio' name='desktop_tab' id='desktop-tab-themes'>"
			. "<label for='desktop-tab-themes'>" . Adminer\h($this->lang('Theme')) . "</label>\n";
		echo "</div>\n";

		echo "<div id='desktop-panels'>\n<div id='desktop-panel-themes'>\n";
		echo "<p class='message'>" . Adminer\h($this->lang('Pick one of each; the system setting decides which applies.')) . "\n";
		// A design is either light or dark -- none upstream ships both -- so it belongs to
		// exactly one of these tables, and the radio group it sits in is what makes it the
		// light choice or the dark one.
		foreach (array("light" => $this->lang('Light'), "dark" => $this->lang('Dark')) as $mode => $label) {
			echo "<h4>" . Adminer\h($label) . "</h4>\n";
			// class=odds is adminer's own zebra striping, and it is overridden in dark.css,
			// so the rows follow whichever design is active instead of us picking colours.
			echo "<table class='odds'>\n";
			echo "<thead><tr><th>" . Adminer\h($this->lang('Design')) . "<th>" . Adminer\h($this->lang('Preview')) . "</thead>\n";
			echo "<tbody>\n";
			$i = 0;
			foreach ($this->designs($mode) as $path => $design) {
				$id = "desktop-design-$mode-" . $i++;
				$checked = ($_SESSION["design_$mode"] == $path ? " checked" : "");
				// Every cell's content is a <label for> the row's input, so clicking the name
				// or the preview selects it, not just the radio itself.
				echo "<tr><td style='white-space: nowrap'>"
					. "<input type='radio' name='design_$mode' value='" . Adminer\h($path) . "' id='$id'$checked>"
					. "<label for='$id' style='display: inline-block'> " . Adminer\h($design) . "</label>"
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
		echo "</div>\n<div id='desktop-panel-plugins'>\n";

		$available = $this->available();
		if ($available) {
			$descriptions = $this->descriptions();
			echo "<h3>" . Adminer\h($this->lang('Plugins')) . "</h3>\n";
			if (!$writable) {
				echo "<p class='error'>" . Adminer\h($this->lang('The plugins folder is read-only.')) . "\n";
			}
			// One per row with what it actually does: 51 bare names is a list you have to
			// already know your way around. The descriptions are the plugins' own.
			echo "<table class='odds'>\n";
			echo "<thead><tr><th>" . Adminer\h($this->lang('Plugin')) . "<th>" . Adminer\h($this->lang('What it does')) . "</thead>\n";
			echo "<tbody>\n";
			foreach ($available as $name => $filename) {
				$id = "desktop-plugin-" . preg_replace('~[^\w-]~', "-", $name);
				$checked = (file_exists($this->link($name)) ? " checked" : "");
				echo "<tr><td style='white-space: nowrap'>"
					. "<input type='checkbox' name='plugins[]' value='" . Adminer\h($name) . "' id='$id'$checked>"
					. "<label for='$id' style='display: inline-block'> " . Adminer\h($name) . "</label>"
					. "<td><label for='$id'>" . Adminer\h($descriptions[$name]) . "</label>\n";
			}
			echo "</tbody>\n</table>\n";
		}
		echo "</div>\n</div>\n";

		echo Adminer\input_hidden("desktop_settings", 1);
		echo Adminer\input_token();
		// Cancel first, primary action last: that is the order every mac dialog uses, and
		// muscle memory puts the confirm button in the bottom right corner.
		echo "<div id='desktop-actions'>";
		echo "<button type='button' id='desktop-close'>" . Adminer\h($this->lang('Cancel')) . "</button>";
		// Same rule the stylesheet highlights rows by: defaultChecked is the attribute as
		// rendered, checked is what it is now. Radios only count when turned on, since
		// choosing a design necessarily turns the previous one off.
		// reset() before closing, or the discarded edits are still sitting there next time
		// the dialog opens, looking like they were kept.
		echo Adminer\script("qsl('button').onclick = function () {
	var n = 0;
	for (var input of qsa('#desktop-panels input')) {
		if (input.type == 'checkbox' ? input.checked != input.defaultChecked : input.checked && !input.defaultChecked) {
			n++;
		}
	}
	if (!n || confirm('" . Adminer\js_escape($this->lang('Unsaved changes: {n}. Close anyway?')) . "'.replace('{n}', n))) {
		qs('#desktop-settings').close();
		this.form.reset();
	}
};");
		echo "<button type='submit' id='desktop-save'" . ($writable ? "" : " disabled") . ">"
			. Adminer\h($this->lang('Save')) . "</button>\n";
		echo "</div>\n</form>\n</dialog>\n";
	}

	protected $translations = array(
		'cs' => array(
			'' => 'Přizpůsobí výchozí hodnoty pro desktopovou aplikaci',
			'Available plugins' => 'Dostupné pluginy',
			'The plugins folder is read-only.' => 'Složka s pluginy je jen pro čtení.',
			'Save' => 'Uložit',
			'(built-in)' => '(vestavěný)',
			'Light' => 'Světlý',
			'Dark' => 'Tmavý',
			'Design' => 'Vzhled',
			'Preview' => 'Náhled',
			'Plugin' => 'Plugin',
			'What it does' => 'Co dělá',
			'Pick one of each; the system setting decides which applies.' => 'Vyberte jeden z každého; který se použije, rozhodne nastavení systému.',
			'Settings' => 'Nastavení',
			'Theme' => 'Vzhled',
			'Plugins' => 'Pluginy',
			'Cancel' => 'Zavřít',
			// {n}, not %d: lang() runs the string through sprintf, which would replace %d with 0
			// before the browser ever sees it.
			'Unsaved changes: {n}. Close anyway?' => 'Neuložené změny: {n}. Přesto zavřít?',
			'Leave both on (built-in) to follow the system theme.' => 'Nechte obojí na (vestavěný), aby se vzhled řídil systémem.',
		),
	);
}
