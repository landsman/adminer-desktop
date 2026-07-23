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

	/** Get shipped designs for one side of the light/dark split, path => label
	* @param string $mode "light" or "dark"
	* @return array<string, string>
	*/
	function designs(string $mode): array {
		$return = array("" => $this->lang('(built-in)'));
		foreach (glob(__DIR__ . "/designs/*/*.css") as $filename) {
			// The whole convention is the filename: designs/<name>/adminer-dark.css is the
			// dark one, adminer.css the light one. No design ships both.
			$is_dark = (bool) preg_match('~-dark~', basename($filename));
			if ($is_dark == ($mode == "dark")) {
				$dir = basename(dirname($filename));
				$return["designs/$dir/" . basename($filename)] = $dir;
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

	function navigation($missing) {
		echo "<form action='' method='post' style='position: fixed; bottom: .5em; right: .5em'>\n";
		foreach (array("light" => $this->lang('Light'), "dark" => $this->lang('Dark')) as $mode => $label) {
			echo "<label style='margin-left: .5em'>" . Adminer\h($label) . " "
				. Adminer\html_select("design_$mode", $this->designs($mode), $_SESSION["design_$mode"], "this.form.submit();")
				. "</label>\n";
		}
		echo Adminer\input_token();
		echo "</form>\n";
	}

	/** Get shipped plugins, name => path; the enabled ones are whatever is symlinked
	* into adminer-plugins/, so the filesystem is the only state there is
	* @return array<string, string>
	*/
	private function available(): array {
		$return = array();
		// Top level only: plugins-available/drivers/ are database drivers, which need a
		// connection to a system we cannot assume exists, not a checkbox.
		foreach (glob(__DIR__ . "/plugins-available/*.php") as $filename) {
			$return[basename($filename, ".php")] = $filename;
		}
		ksort($return);
		return $return;
	}

	private function link(string $name): string {
		return __DIR__ . "/adminer-plugins/$name.php";
	}

	function afterConnect() {
		// Hidden marker, not the checkbox array: unticking the last plugin posts no
		// array at all, and that has to mean "disable everything", not "do nothing".
		if (!$_POST["desktop_plugins"] || !Adminer\verify_token()) {
			return;
		}
		// Whitelist by construction — we iterate what we ship and only look the POSTed
		// names up in it, so nothing user-supplied ever reaches a filesystem path.
		$wanted = array_flip((array) $_POST["plugins"]);
		foreach ($this->available() as $name => $filename) {
			$link = $this->link($name);
			if (isset($wanted[$name])) {
				if (!file_exists($link)) {
					// Relative target, so it survives the whole app/ folder being moved
					// into a .app bundle.
					@symlink("../plugins-available/$name.php", $link);
				}
			} elseif (is_link($link)) {
				// is_link, never file_exists: a real .php the user dropped in by hand is
				// theirs, and must not be deletable by a checkbox that never listed it.
				@unlink($link);
			}
		}
		Adminer\redirect($_SERVER["REQUEST_URI"]);
	}

	function pluginsLinks() {
		$available = $this->available();
		if (!$available) {
			return;
		}
		$writable = is_writable(dirname($this->link("x")));
		echo "<h3>" . Adminer\h($this->lang('Available plugins')) . "</h3>\n";
		if (!$writable) {
			echo "<p class='error'>" . Adminer\h($this->lang('The plugins folder is read-only.')) . "\n";
		}
		echo "<form action='' method='post'>\n<ul style='columns: 3; list-style: none; padding: 0'>\n";
		foreach ($available as $name => $filename) {
			// ponytail: names only, no descriptions. Rendering those means including all
			// 51 files on every page load to read their doc-comments, and the names
			// (dump-json, login-ip, table-structure) already say what they do. Include
			// them if that ever stops being true.
			echo "<li>" . Adminer\checkbox("plugins[]", $name, file_exists($this->link($name)), $name) . "\n";
		}
		echo "</ul>\n";
		echo Adminer\input_hidden("desktop_plugins", 1);
		echo Adminer\input_token();
		echo "<input type='submit' value='" . Adminer\h($this->lang('Save')) . "'"
			. ($writable ? "" : " disabled") . ">\n";
		echo "</form>\n";
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
		),
	);
}
