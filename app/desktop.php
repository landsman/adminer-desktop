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

	/** Get shipped designs for one side of the light/dark split, path => label
	* @param string $mode "light" or "dark"
	* @return array<string, string>
	*/
	function designs(string $mode): array {
		$return = array("" => $this->lang('(built-in)'));
		foreach (glob(__DIR__ . "/designs/*/*.css") as $filename) {
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
		foreach (glob(__DIR__ . "/plugins-available/*.php") as $filename) {
			$return[basename($filename, ".php")] = $filename;
		}
		ksort($return);
		return $return;
	}

	private function link(string $name): string {
		return __DIR__ . "/adminer-plugins/$name.php";
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

	function navigation($missing) {
		$writable = is_writable(dirname($this->link("x")));
		// <dialog> rather than a hand-rolled overlay: it brings the backdrop, focus
		// trapping, top-layer stacking and escape-to-close with it, and needs no library.
		echo "<button type='button' id='desktop-gear' title='" . Adminer\h($this->lang('Settings'))
			. "' style='position: fixed; bottom: .5em; right: .5em; font-size: 1.2em; line-height: 1; padding: .3em .5em; cursor: pointer'>&#9881;</button>";
		// Adminer sets a CSP nonce on its scripts, so behaviour is attached via its own
		// script()/qsl() helpers; an inline onclick attribute would be blocked.
		echo Adminer\script("qsl('button').onclick = function () { qs('#desktop-settings').showModal(); };");

		echo "<dialog id='desktop-settings' style='max-width: 40em; padding: 1em'>\n";
		echo "<form action='' method='post'>\n";

		echo "<h3>" . Adminer\h($this->lang('Theme')) . "</h3>\n";
		// Two selects because no design upstream ships both variants: each is either
		// light-only or dark-only, and auto-switching needs one of each.
		foreach (array("light" => $this->lang('Light'), "dark" => $this->lang('Dark')) as $mode => $label) {
			echo "<label style='margin-right: 1em'>" . Adminer\h($label) . " "
				. Adminer\html_select("design_$mode", $this->designs($mode), $_SESSION["design_$mode"])
				. "</label>\n";
		}
		echo "<p class='message'>" . Adminer\h($this->lang('Leave both on (built-in) to follow the system theme.')) . "\n";

		$available = $this->available();
		if ($available) {
			echo "<h3>" . Adminer\h($this->lang('Plugins')) . "</h3>\n";
			if (!$writable) {
				echo "<p class='error'>" . Adminer\h($this->lang('The plugins folder is read-only.')) . "\n";
			}
			echo "<ul style='columns: 2; list-style: none; padding: 0; max-height: 22em; overflow: auto'>\n";
			foreach ($available as $name => $filename) {
				// ponytail: names only, no descriptions. Rendering those means including all
				// 51 files on every page load to read their doc-comments, and the names
				// (dump-json, login-ip, table-structure) already say what they do.
				echo "<li>" . Adminer\checkbox("plugins[]", $name, file_exists($this->link($name)), $name) . "\n";
			}
			echo "</ul>\n";
		}

		echo Adminer\input_hidden("desktop_settings", 1);
		echo Adminer\input_token();
		echo "<p><input type='submit' value='" . Adminer\h($this->lang('Save')) . "'"
			. ($writable ? "" : " disabled") . ">\n";
		echo "<button type='button' id='desktop-close'>" . Adminer\h($this->lang('Cancel')) . "</button>\n";
		echo Adminer\script("qsl('button').onclick = function () { qs('#desktop-settings').close(); };");
		echo "</form>\n</dialog>\n";
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
			'Settings' => 'Nastavení',
			'Theme' => 'Vzhled',
			'Plugins' => 'Pluginy',
			'Cancel' => 'Zavřít',
			'Leave both on (built-in) to follow the system theme.' => 'Nechte obojí na (vestavěný), aby se vzhled řídil systémem.',
		),
	);
}
