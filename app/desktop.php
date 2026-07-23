<?php
declare(strict_types=1);

/** Adapt Adminer's defaults to running as a desktop app.
*
* This file is the plugin adminer sees: the hooks, and the strings. The work behind them
* lives in settings/, one file per concern, so that this stays a map of what is hooked
* rather than a pile of everything.
*
* @license https://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
*/

require_once __DIR__ . "/styles/styles.php";
require_once __DIR__ . "/settings/theme/theme.php";
require_once __DIR__ . "/settings/plugins/plugins.php";
require_once __DIR__ . "/settings/dialog.php";

class AdminerDesktop extends Adminer\Plugin {
	/** @var Desktop\Styles */ private $styles;
	/** @var Desktop\Theme */ private $theme;
	/** @var Desktop\PluginList */ private $plugins;
	/** @var Desktop\Dialog */ private $dialog;

	function __construct() {
		$this->styles = new Desktop\Styles(__DIR__ . "/styles/css");
		$this->theme = new Desktop\Theme($this);
		$this->plugins = new Desktop\PluginList($this);
		$this->dialog = new Desktop\Dialog($this, $this->theme, $this->plugins);
	}

	/** Translate. lang() is protected on Plugin, and the classes in settings/ need it;
	* keeping every string in one $translations below is also one file for a translator.
	* @param literal-string $idf
	*/
	function t(string $idf): string {
		return $this->lang($idf);
	}

	/** Get this directory with forward slashes.
	* __DIR__ is backslash-separated on Windows, and glob() treats a backslash as an
	* escape character there, so a pattern built from raw __DIR__ silently matches
	* nothing — no designs, no plugins, and no error to say why. PHP accepts forward
	* slashes on every platform.
	*/
	function dir(): string {
		return str_replace('\\', '/', __DIR__);
	}

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

	function head($dark = null) {
		$this->styles->link();
		return null; // let adminer's own head() run; it prints the favicon
	}

	function css() {
		return $this->theme->cssMap();
	}

	function navigation($missing) {
		$this->dialog->render();
	}

	/** Apply settings posted from the dialog. Called from adminer-plugins.php rather than
	* from a hook: it runs after session_start() and before any output, which is what both
	* the session write and the redirect need. afterConnect() would only fire once you are
	* connected, so nothing here would work on the login screen.
	*/
	function handlePost(): void {
		if (!$_POST["desktop_settings"] || !Adminer\verify_token()) {
			return;
		}
		Adminer\restart_session();
		$this->theme->apply();
		$this->plugins->apply();
		Adminer\redirect($_SERVER["REQUEST_URI"]);
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
