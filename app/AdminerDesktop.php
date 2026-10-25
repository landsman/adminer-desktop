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

use Desktop\Assets\Icon;
use Desktop\Assets\Javascript;
use Desktop\Assets\Styles;
use Desktop\Connections\Connections;
use Desktop\DestructiveAction;
use Desktop\Env;
use Desktop\I18n\Catalog;
use Desktop\I18n\Domain;
use Desktop\I18n\Locale;
use Desktop\Import;
use Desktop\Latte;
use Desktop\Mcp;
use Desktop\Os;
use Desktop\SettingKey;
use Desktop\Settings\Dialog;
use Desktop\Settings\Plugins\PluginList;
use Desktop\Settings\Theme\Theme;
use Desktop\Shortcuts\Shortcuts;
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
	private Mcp\Panel $mcp;
	private UserSettings $userSettings;
	private Connections $connections;

	function __construct() {
		// Before anything reads the request: sql.inc.php parses the import as soon as it
		// is included, and there is no hook between the two. See Desktop\Import.
		Import::defuse();
		// The plugin UI strings live in app/src/I18n/plugin/ (per-language files, dotted IDs) and
		// feed Adminer's Plugin::lang() through $translations. See Desktop\I18n\Catalog.
		$this->translations = Catalog::load(Domain::Plugin)->all();
		// What {t()} translates through, in templates that are handed no $desktop (Latte::engine()).
		Latte::engine()->addProvider("desktop", $this);
		$this->userSettings = new UserSettings();
		$this->styles = new Styles(__DIR__ . "/src/Assets/css");
		// dir(), not raw __DIR__: Javascript globs its folder, and glob() treats the
		// backslashes in a Windows __DIR__ as escapes, matching nothing.
		$this->javascript = new Javascript($this->dir() . "/src/Assets/javascript");
		$this->theme = new Theme($this, $this->userSettings);
		$this->plugins = new PluginList($this, $this->userSettings);
		$this->mcp = new Mcp\Panel($this, $this->userSettings);
		$this->connections = new Connections($this, $this->userSettings);
		$this->dialog = new Dialog($this, $this->theme, $this->plugins, $this->mcp, new Shortcuts($this));
	}

	/** Answer an agent's MCP call, and keep the handshake that let it find us current.
	*
	* headers() is the earliest hook that runs with a live connection — sql-gemini.php answers
	* its own POST from here for the same reason — and it runs before adminer has produced any
	* page, so writing a JSON body and exiting leaves nothing half-rendered behind. The work is
	* in Desktop\Mcp\Endpoint; this stays a map of what is hooked.
	*
	* @return null always: this hook has no opinion for other plugins, and the MCP path exits
	*/
	function headers(): ?string {
		return (new Mcp\Endpoint($this->userSettings, connections: $this->connections))->serve();
	}

	/** The catalogue, so adminer-plugins.php can hand adminer the enabled plugins.
	*
	* Not a hook, despite living on a plugin: adminer only dispatches to methods its own
	* Adminer class declares, and it has no plugins().
	*/
	function plugins(): PluginList {
		return $this->plugins;
	}

	/** Translate a plugin-UI string by its ID. lang() is protected on Plugin and the classes in
	* src/ need it; the IDs and their text live in app/src/I18n/plugin/ (loaded into $translations).
	* @param literal-string $idf
	*/
	function t(string $idf): string {
		return $this->lang($idf);
	}

	/** The plugin's description in Adminer's plugin list. Adminer's Plugin::description() returns
	* lang(''), a bare empty-string key; override it to use a normal ID so the description sits in
	* the language files with every other string rather than as a special case.
	* @return string
	*/
	function description(): string {
		return $this->t('plugin.description');
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
			// doesn't silently drift when the login form changes upstream — either quote,
			// because 6.0 moved the field from double to single ones. The heading goes back
			// in too: the hook answers for the whole row, and without its <tr> the browser
			// hoists the bare input out of the table.
			return $heading . preg_replace('~value=([\'"])\1~', 'value=${1}127.0.0.1$1', $value) . "\n";
		}
		return null; // let adminer handle every other field
	}

	/** The saved connections as cards — or a line saying there are none yet — with adminer's own
	* login form in a "New connection" modal. Always answers, so adminer never prints its form.
	* @return true
	*/
	function loginForm() {
		$this->connections->loginPage();
		return true;
	}

	/** Count failed logins per app instance, not per IP. Adminer keeps them in one file in the
	* system temp dir keyed by the client's address, and every instance on this machine — the
	* installed app, a checkout, each e2e run — is 127.0.0.1, so an e2e run's wrong passwords
	* locked the real app out for half an hour. The data dir is what tells instances apart.
	*/
	function bruteForceKey(): string {
		return $_SERVER["REMOTE_ADDR"] . ' ' . Env::DataDir->get();
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
	* @return string|null
	*/
	function head(): ?string {
		$this->styles->link();
		Icon::preload();
		$this->javascript->link();
		$this->javascript->beforePaint("header.js");
		$this->javascript->beforePaint("open-on-load.js");
		// Restore the sizes the user set themselves — the sidebar, and the edit form's fields —
		// before the body paints, so a cold start opens at them instead of flashing the default
		// then jumping. Each is a property the theme reads, named by what the api stored
		// (Api\ResizePreference). The stored values are already clamped integers; cast again so
		// nothing but a number can reach the stylesheet. The CSP has no style-src, so an inline
		// <style> needs no nonce — and only our own theme reads the properties.
		$properties = ['sidebar' => '--ad-sidebar-dragged', 'edit_field' => '--ad-edit-field-width'];
		$resized = $this->userSettings->get(SettingKey::UserResized, []);
		$css = '';
		foreach (is_array($resized) ? $resized : [] as $what => $size) {
			if (isset($properties[$what])) {
				$css .= $properties[$what] . ':' . (int) $size . 'px;';
			}
		}
		// The colour of the connection this window is in, for the stripe and the badge
		// (connections.css). Whitelisted in Connections, so it is only ever a hex colour.
		if ($color = $this->connections->current()?->color()) {
			$css .= '--ad-conn:' . $color . ';';
		}
		if ($css !== '') {
			echo "<style>:root{" . $css . "}</style>\n";
		}
		// The sidebar is named per database, so the view transition pairs it only while it lists
		// the same tables: opening another database slides the new list in (base.css) instead of
		// holding the old one still. Hashed, so the name is always a valid CSS identifier; `body`
		// outranks the theme's own #foot rule, which loads after this.
		$db = defined('Adminer\DB') ? (string) Adminer\DB : '';
		echo "<style>body #foot{view-transition-name:ad-sidebar-" . substr(md5($db), 0, 8) . "}</style>\n";
		// Adminer prints the sidebar after the content, and a long page paints before the parser
		// gets there: the transition then runs without it and it pops in afterwards, in place. Hold
		// the first paint until it is parsed.
		echo "<link rel=\"expect\" href=\"#foot\" blocking=\"render\">\n";
		// `make demo` forwards the throwaway connection here; src/Assets/javascript/demo-login.js
		// fills it into the login form and submits. Only `make demo` ever sets this, so a
		// shipped build never defines the global and the script stays inert.
		if ($demo = Env::Demo->get()) {
			echo Adminer\script("(window.adminerDesktop ??= {}).demo = " . json_encode($demo) . ";");
		}
		// The strings our scripts show, for window.adminerDesktop.lang
		// (src/Assets/javascript/api.js): every catalogue ID under js., translated, in one JSON
		// block — so a new one is a catalogue entry and nothing here. A data block, never
		// executed, so the CSP has nothing to say about it; JSON_HEX_TAG keeps a "</script>" or
		// "<!--" in a translation from breaking out of it.
		$strings = [];
		foreach (array_keys($this->translations[Locale::En->value]) as $id) {
			if (str_starts_with($id, 'js.')) {
				$strings[substr($id, 3)] = $this->lang($id);
			}
		}
		echo '<script type="application/json" id="ad-i18n">' . json_encode($strings, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) . "</script>\n";
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
		echo " " . Os::current()->bodyClass();
		// The launcher sets this under -debug; the desktop scripts read it to stand down so
		// the web inspector's own behaviour is unobstructed.
		if (Env::Debug->get()) {
			echo " debug";
		}
		// fresh-on-back.js reloads a page Back restored, but never one that answered a POST.
		if ($_SERVER["REQUEST_METHOD"] == "POST") {
			echo " ad-posted";
		}
		// From the server rather than the script that toggles it, so a hidden sidebar is never
		// painted on the way to being hidden.
		if ($this->userSettings->get(SettingKey::SidebarHidden, false) === true) {
			echo " ad-sidebar-hidden";
		}
		$this->theme->bodyClass();
		$current = $this->connections->current();
		// The stripe in the connection's colour marks the pages that change something, not every
		// page of it.
		if ($current?->color() && DestructiveAction::current()) {
			echo " ad-writing";
		}
		if ($current?->environment) {
			echo " env-" . $current->environment->value;
		}
	}

	/** The logo leads to the start screen (home()), not out to adminer.org in a new
	* window — in a desktop app that opened a browser nobody asked for. Adminer's own markup with
	* only the link swapped, so the logo and its versioned URL stay adminer's; the address is the
	* one adminer builds for the breadcrumb's first link (include/design.inc.php).
	*/
	function name(): string {
		$name = (new Adminer\Adminer)->name();
		// On the login page there is no home to lead to — it is the home — so the logo is a name
		// and not a link.
		if (!isset($_GET["username"])) {
			return (string) preg_replace("~<a href='[^']*'[^>]*?( id='h1'[^>]*>)(.*?)</a>~s", '<span$1$2</span>', $name);
		}
		return (string) preg_replace("~<a href='[^']*'[^>]*?(?= id='h1')~", "<a href='" . Adminer\h($this->home()) . "'", $name);
	}

	/** The start screen with the saved connections, still signed in: this server without the
	* user. The logo and the connection badge lead there. */
	function home(): string {
		return substr((string) preg_replace('~\b(username|db|ns)=[^&]*&~', '', Adminer\ME), 0, -1) ?: ".";
	}

	/** Adminer's sidebar, with ours ahead of it: the activity indicator (busy.js moves it out), the
	* settings dialog, the search box once logged in (and the editor of a connection just added)
	* and, on the login page, the versions beside its gear and the dialogs that edit the saved
	* connections.
	* @param string $missing
	* @return void
	*/
	function navigation($missing): void { // phpcs:ignore SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingNativeTypeHint -- adminer's own signature is untyped
		Latte::engine()->render(__DIR__ . "/src/Assets/busy.latte");
		if ($missing != "auth") {
			$this->dialog->render();
			Latte::engine()->render(__DIR__ . "/src/Search/search-dialog.latte", ["desktop" => $this]);
			$this->connections->offer();
			return;
		}
		// One row for the versions and the gear, so our design can centre them as a pair.
		echo "<div class='ad-login-foot'>";
		$this->connections->versions();
		$this->dialog->render();
		echo "</div>\n";
		$this->connections->dialogs();
	}

	/** The badge of the connection you are in, under the logo and the gear rather than above them.
	* navigation() runs before adminer prints the logo; this is the first hook after it, and adminer
	* only calls it once you are logged in. Prints and abstains, so the database list follows.
	* @param string $missing
	* @return null
	*/
	function databasesPrint($missing) { // phpcs:ignore SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingNativeTypeHint -- adminer's own signature is untyped
		$this->connections->badge();
		return null;
	}

	/** The sidebar's actions as icons, adminer's own label kept as the tooltip and as the name a
	* screen reader reads. Changed in place rather than returned, so a later plugin still gets its
	* turn: an action of its own stays text.
	* @param array<string,string> $actions
	*/
	function menuActions(array &$actions, string $missing): void {
		$icons = ['sql' => Icon::Sql, 'import' => Icon::Import, 'dump' => Icon::Export, 'create' => Icon::CreateTable];
		foreach (array_intersect_key($icons, $actions) as $key => $icon) {
			$actions[$key] = (string) preg_replace_callback(
				'~^(<a\b[^>]*)>(.*)</a>$~s',
				fn(array $m): string => "$m[1] title='" . Adminer\h(strip_tags($m[2])) . "'>" . $icon->html() . "</a>",
				$actions[$key],
			);
		}
	}

	/** A table's or column's comment, allowed to wrap (.ad-text-wrap, utilities.css). Adminer prints
	* both in a `nowrap` cell or paragraph, so one long comment widened the whole structure table
	* past the window. Routine comments abstain: adminer formats those itself.
	*/
	function commentValue(string $type, ?string $comment): ?string {
		if ($comment == "" || ($type != 'TABLE' && $type != 'COLUMN')) {
			return null;
		}
		return "<span class='ad-text-wrap'>" . Adminer\h($comment) . "</span>";
	}

	/** Apply settings posted from the dialog. Called from adminer-plugins.php rather than
	* from a hook: it runs after session_start() and before any output, which is what both
	* the session write and the redirect need. afterConnect() would only fire once you are
	* connected, so nothing here would work on the login screen.
	*/
	function handlePost(): void {
		$this->connections->follow();
		// empty(), not adminer's bare $_POST["x"]: this one runs on every request, including
		// every GET, so the warning it would leave is on every page in the debug bar.
		if (!empty($_POST["desktop_connection"]) && Adminer\verify_token()) {
			$key = (string) $_POST["desktop_connection"];
			match ($_POST["desktop_connection_action"] ?? "") {
				"logout" => $this->connections->logout($key),
				"forget" => $this->connections->forget($key),
				default => $this->connections->apply(),
			};
			Adminer\redirect($_SERVER["REQUEST_URI"]);
		}
		if (empty($_POST["desktop_settings"]) || !Adminer\verify_token()) {
			return;
		}
		Adminer\restart_session();
		// Reset instead of applying, never as well as: the same post carries every field the
		// dialog had open, so applying them after the file is gone would write the old answers
		// straight back and reset nothing.
		if (!empty($_POST["desktop_reset"])) {
			$this->userSettings->reset();
		} else {
			$this->theme->apply();
			$this->plugins->apply();
			$this->mcp->apply();
		}
		Adminer\redirect($_SERVER["REQUEST_URI"]);
	}
}
