<?php
declare(strict_types=1);

/** Adapt Adminer's defaults to running as a desktop app.
*
* This file is the plugin adminer sees: the hooks, and the strings. The work behind them
* lives in src/, one file per concern, so that this stays a map of what is hooked
* rather than a pile of everything.
*
* @license https://www.apache.org/licenses/LICENSE-2.0 Apache License, Version 2.0
*/

use Desktop\Assets\Javascript;
use Desktop\Assets\Styles;
use Desktop\Env;
use Desktop\Import;
use Desktop\SettingKey;
use Desktop\Settings\Dialog;
use Desktop\Settings\Plugins\PluginList;
use Desktop\Settings\Theme\Theme;
use Desktop\UserSettings;

// Every class below autoloads via PSR-4 (Desktop\ -> src/). This file itself is not — it is
// the global-namespace plugin adminer instantiates, required by adminer-plugins.php once the
// autoloader is on.
class AdminerDesktop extends Adminer\Plugin {
	private Styles $styles;
	private Javascript $javascript;
	private Theme $theme;
	private PluginList $plugins;
	private Dialog $dialog;
	private UserSettings $userSettings;

	function __construct() {
		// Before anything reads the request: sql.inc.php parses the import as soon as it
		// is included, and there is no hook between the two. See Desktop\Import.
		Import::defuse();
		$this->userSettings = new UserSettings();
		$this->styles = new Styles(__DIR__ . "/src/Assets/css");
		// dir(), not raw __DIR__: Javascript globs its folder, and glob() treats the
		// backslashes in a Windows __DIR__ as escapes, matching nothing.
		$this->javascript = new Javascript($this->dir() . "/src/Assets/javascript");
		$this->theme = new Theme($this, $this->userSettings);
		$this->plugins = new PluginList($this);
		$this->dialog = new Dialog($this, $this->theme, $this->plugins);
	}

	/** Translate. lang() is protected on Plugin, and the classes in src/ need it;
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

	/**
	* @param string $name
	* @param string $heading
	* @param string $value
	* @return string|null
	*/
	function loginFormField(string $name, string $heading, string $value): ?string {
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

	/**
	* @param bool $create
	* @return string
	*/
	function permanentLogin(bool $create = false): string {
		// Adminer already remembers servers and databases you have logged into and offers
		// them for one click on the login page — but only for as long as the key behind
		// "Permanent login" survives, and upstream keeps that key in get_temp_dir(). On
		// macOS that is /var/folders/…/T, which the OS cleans out, so the saved list
		// silently expires with "Master password expired".
		// Same mechanism, same adminer helpers, durable location. The launcher passes the
		// path in, so the per-OS choice stays in one place (Go's os.UserConfigDir) instead
		// of being restated here for macOS, Linux and Windows.
		$dir = Env::DataDir->get();
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

	/**
	* @param bool|null $dark
	* @return string|null
	*/
	function head(?bool $dark = null): ?string {
		$this->styles->link();
		$this->javascript->link();
		// Restore the sidebar to the width the user last dragged it to, before the body paints,
		// so a cold start opens at that width instead of flashing the default then jumping. The
		// stored value is already a clamped integer (src/Settings/sidebar-width.php); cast again
		// so nothing but a number can reach the stylesheet. The CSP has no style-src, so an
		// inline <style> needs no nonce — and only our own islands layout reads the property.
		$width = $this->userSettings->get(SettingKey::SidebarWidth);
		if ($width !== null) {
			echo "<style>:root{--ad-sidebar-width:" . (int) $width . "px}</style>\n";
		}
		// `make demo` forwards the throwaway connection here; src/Assets/javascript/demo-login.js
		// fills it into the login form and submits. Only `make demo` ever sets this, so a
		// shipped build never defines the global and the script stays inert.
		if ($demo = Env::Demo->get()) {
			echo Adminer\script("window.desktopDemo = " . json_encode($demo) . ";");
		}
		// The whole-page import dropzone (src/Assets/javascript/drag-drop-import.js) is client
		// side, but its label is ours to translate — so hand it over server-side in a meta the
		// script reads. Only on the import page, which is the one the dropzone wires itself to.
		if (isset($_GET["import"])) {
			echo '<meta name="ad-import-drop" content="' . Adminer\h($this->t('Drop the SQL file to import')) . "\">\n";
		}
		return null; // let adminer's own head() run; it prints the favicon
	}

	/** @return array<string,string> */
	function css(): array {
		return $this->theme->cssMap();
	}

	/** Under -debug, let Tracy's own inline scripts run so the debug bar actually renders
	* (its panels — the settings one included — are otherwise built and never shown). The
	* script-src already lists 'unsafe-inline'; only 'strict-dynamic' suppresses it, telling
	* the browser to trust nonced scripts and the scripts they load and nothing else. Dropping
	* just that one token re-enables inline here — never outside -debug, the same line the web
	* inspector and Tracy itself hold.
	* @param array<int,array<string,string>> $csp
	* @return void
	*/
	function csp(array &$csp): void {
		if (!Env::Debug->get()) {
			return;
		}
		foreach ($csp as &$set) {
			if (isset($set["script-src"])) {
				$set["script-src"] = trim(str_replace("'strict-dynamic'", "", $set["script-src"]));
			}
		}
	}

	/** Tag <body> with the host OS and the chosen row density, for the theme to key off.
	* Adminer dispatches this hook to every plugin and to its own base (which echoes
	* " adminer"), concatenating whatever each echoes — so this adds classes rather than
	* replacing them. PHP_OS_FAMILY is the real OS because frankenphp runs on the machine;
	* no launcher env var is needed for it.
	* @return void
	*/
	function bodyClass(): void {
		$os = ["Darwin" => "os-mac", "Windows" => "os-windows", "Linux" => "os-linux"];
		echo " " . ($os[PHP_OS_FAMILY] ?? "os-linux");
		// The launcher sets this under -debug; the desktop scripts read it to stand down so
		// the web inspector's own behaviour is unobstructed.
		if (Env::Debug->get()) {
			echo " debug";
		}
		$this->theme->bodyClass();
	}

	/**
	* @param mixed $missing
	* @return void
	*/
	function navigation(mixed $missing): void {
		$this->dialog->render();
	}

	/** Apply settings posted from the dialog. Called from adminer-plugins.php rather than
	* from a hook: it runs after session_start() and before any output, which is what both
	* the session write and the redirect need. afterConnect() would only fire once you are
	* connected, so nothing here would work on the login screen.
	*/
	function handlePost(): void {
		// empty(), not adminer's bare $_POST["x"]: this one runs on every request, including
		// every GET, so the warning it would leave is on every page in the debug bar.
		if (empty($_POST["desktop_settings"]) || !Adminer\verify_token()) {
			return;
		}
		Adminer\restart_session();
		$this->theme->apply();
		$this->plugins->apply();
		Adminer\redirect($_SERVER["REQUEST_URI"]);
	}

	/** @var array<string,array<string,string>> */
	protected $translations = [ // phpcs:ignore SlevomatCodingStandard.TypeHints.PropertyTypeHint.MissingNativeTypeHint -- overrides adminer's untyped Adminer\Plugin::$translations, which PHP forbids narrowing
		'cs' => [
			'' => 'Přizpůsobí výchozí hodnoty pro desktopovou aplikaci',
			'Available plugins' => 'Dostupné pluginy',
			'The plugins folder is read-only.' => 'Složka s pluginy je jen pro čtení.',
			'Save' => 'Uložit',
			'Light' => 'Světlý',
			'Dark' => 'Tmavý',
			'Design' => 'Vzhled',
			'Preview' => 'Náhled',
			'Language' => 'Jazyk',
			'Row density' => 'Hustota řádků',
			'Compact' => 'Kompaktní',
			'Cozy' => 'Střední',
			'Comfortable' => 'Vzdušný',
			'Scaling' => 'Měřítko',
			'Appearance' => 'Barevný režim',
			'Sync with OS' => 'Podle systému',
			'Adminer Desktop follows the system light and dark, or pin it to Light or Dark. Either scheme uses the design chosen for it below.' => 'Adminer Desktop se řídí světlým a tmavým režimem systému, nebo ho můžete připnout na Světlý či Tmavý. Každý režim použije vzhled zvolený níže.',
			'Plugin' => 'Plugin',
			'What it does' => 'Co dělá',
			'Settings' => 'Nastavení',
			'Theme' => 'Vzhled',
			'Plugins' => 'Pluginy',
			'Cancel' => 'Zavřít',
			'Drop the SQL file to import' => 'Přetáhněte sem SQL soubor pro import',
			// {n}, not %d: lang() runs the string through sprintf, which would replace %d with 0
			// before the browser ever sees it.
			'Unsaved changes: {n}. Close anyway?' => 'Neuložené změny: {n}. Přesto zavřít?',
		],
	];
}
