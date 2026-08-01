<?php
declare(strict_types=1);

namespace Desktop\Settings\Plugins;

use Desktop\Latte;
use Desktop\SettingKey;
use Desktop\UserSettings;

/** The plugin half of the settings dialog: what we ship, what the user turned on, and the
* panel that toggles them.
*
* Named PluginList rather than Plugins to stay clear of both adminer's own Plugins class
* and its rule of instantiating anything called Adminer* as a plugin.
*/
class PluginList {
	/** What we ship, as the class each one declares.
	*
	* Hand-picked, not globbed. The release carries 51 and most of them answer a question a
	* desktop app never asks. Nine cannot be constructed at all without arguments — adminer
	* refuses those and prints why in place of the plugin (include/plugins.inc.php:30) — and
	* of the rest, some want a reverse proxy or an MTA that is not there, some fetch their
	* editor from a CDN an offline app cannot reach, and some offer a second copy of a
	* setting this app already owns. What is left is what works by ticking a box.
	*
	* The class is the one thing written down: we instantiate it ourselves (see instances()),
	* and the file it lives in follows from it (see name()) rather than being spelled out
	* beside it, where the two could disagree.
	* @var array<class-string>
	*/
	private const array PICKED = [
		\AdminerBackwardKeys::class,
		\AdminerBeforeUnload::class,
		\AdminerDumpAlter::class,
		\AdminerDumpBz2::class,
		\AdminerDumpDate::class,
		\AdminerDumpJson::class,
		\AdminerDumpPhp::class,
		\AdminerDumpXml::class,
		\AdminerDumpZip::class,
		\AdminerEditForeign::class,
		\AdminerEditTextarea::class,
		\AdminerEditorViews::class,
		\AdminerEnumOption::class,
		\AdminerForeignSystem::class,
		\AdminerJsonColumn::class,
		\AdminerPrettyJsonColumn::class,
		\AdminerRowNumbers::class,
		\AdminerSlugify::class,
		\AdminerTableIndexesStructure::class,
		\AdminerTableStructure::class,
		\AdminerTablesFilter::class,
		\AdminerTranslation::class,
	];

	/** On whatever the user picked, and not offered as a choice.
	*
	* Adminer fetches https://www.adminer.org/version/ from <body onload> unless a cookie
	* says otherwise, and answers with a version this app cannot install — the adminer we
	* bundle is pinned in the Makefile. The plugin is three lines replacing verifyVersion()
	* with a no-op, so the check never leaves the machine.
	* @var array<class-string>
	*/
	private const array ALWAYS = [
		\AdminerVersionNoverify::class,
	];

	/** Shipped on, until the user says otherwise.
	*
	* This is the list a release changes, and settings.json stores the user's answer against
	* it rather than a bare set of names — so adding one here cannot switch it back on for
	* someone who had turned it off.
	*
	* AdminerTablesFilter to start with: a filter box over the table list is what every
	* desktop database browser has, and the plugin is one `tablesPrint` hook, so it touches
	* nothing until there is a list of tables to filter.
	* @var array<class-string>
	*/
	private const array DEFAULT_ON = [
		\AdminerTablesFilter::class,
	];

	/** What we construct a plugin with, where its own default is not the one we want.
	*
	* AdminerEditForeign turns a foreign key into a <select>, and upstream's default limit of 0
	* means no LIMIT at all — every row of the referenced table, fetched to fill a dropdown, on
	* a form the user only wanted to edit one row in. Past the limit the plugin returns nothing
	* and adminer's plain input stands, so 100 is "a dropdown for a small lookup table, and no
	* stall on a big one".
	*
	* This is the seam adminer's own loader does not have (include/plugins.inc.php:38 refuses
	* anything it would have to guess an argument for), and where a plugin wanting a real
	* setting — a log file under the data dir, a timeout from the UI — would read UserSettings.
	* @var array<class-string, array<mixed>>
	*/
	private const array ARGUMENTS = [
		\AdminerEditForeign::class => [100],
	];

	/** The file in available/ a plugin lives in: AdminerTablesFilter => tables-filter.
	*
	* Adminer builds both names out of the same words, so the file follows from the class and
	* only the class has to be written down. A plugin that broke that rule would need a special
	* case here, and nothing else would complain — a name answering to no file is skipped, not
	* fatal, so the plugin would just stop being there. check.sh asserts every name has one.
	* @param class-string $class
	*/
	private static function name(string $class): string {
		return strtolower((string) preg_replace('~(?<!^)(?=[A-Z])~', "-", substr($class, strlen("Adminer"))));
	}

	/** The catalogue keyed by the file each class lives in — the shape everything below wants,
	* because a filename is what settings.json stores and what the panel posts back.
	* @param array<class-string> $classes
	* @return array<string, class-string>
	*/
	private static function byName(array $classes): array {
		$return = [];
		foreach ($classes as $class) {
			$return[self::name($class)] = $class;
		}
		return $return;
	}

	private \AdminerDesktop $desktop;
	private UserSettings $settings;

	function __construct(\AdminerDesktop $desktop, UserSettings $settings) {
		$this->desktop = $desktop;
		$this->settings = $settings;
	}

	/** The names we ship, for anything that has to walk them without an adminer request
	* behind it — check.sh boots each one in turn. Static so it needs no instance.
	* @return array<string>
	*/
	static function names(): array {
		return array_keys(self::byName(self::PICKED));
	}

	/** What is on, in catalogue order: the defaults, with the user's answers on top.
	* @return array<string>
	*/
	function enabled(): array {
		$answers = $this->answers();
		$on = self::byName(self::DEFAULT_ON);
		return array_values(array_filter(
			self::names(),
			fn(string $name): bool => $answers[$name] ?? isset($on[$name]),
		));
	}

	/** What the user said about each plugin, where it differs from what we ship — name => on.
	*
	* Both answers are stored, not just the yesses, because "off" is a real answer once we
	* ship anything on by default: a name simply missing from an enabled-only list is
	* indistinguishable from one the user turned off, and the next release would switch it
	* back on under them.
	*
	* Only the differences, so a default we change later reaches everyone who never had an
	* opinion about it — and a stored answer that agrees with the new default is dropped on
	* the next save rather than pinning a preference nobody expressed.
	*
	* No version alongside the names: every plugin comes out of the adminer release pinned in
	* the Makefile, so one here could only ever disagree with the file actually loaded.
	* @return array<string, bool>
	*/
	private function answers(): array {
		$stored = $this->settings->get(SettingKey::Plugins, []);
		$picked = self::byName(self::PICKED);
		$return = [];
		foreach ((is_array($stored) ? $stored : []) as $name => $on) {
			// A list of names is the shape this key had before it could say "off"; every
			// name in one was a plugin turned on.
			if (is_int($name)) {
				$name = (string) $on;
				$on = true;
			}
			if (isset($picked[$name])) { // anything we no longer ship drops out here
				$return[$name] = (bool) $on;
			}
		}
		return $return;
	}

	/** The plugin objects adminer should register, constructed by us.
	*
	* Adminer instantiates every Adminer* class that happens to be declared, so the
	* catalogue is not somewhere it can find and nothing includes it wholesale; only what
	* is enabled is included, and adminer gets the instances (adminer-plugins.php). That is
	* also the seam for a plugin that needs constructor arguments (see ARGUMENTS): a line
	* here, rather than the error adminer shows when it has to guess one.
	* @return array<object>
	*/
	function instances(): array {
		$return = [];
		$picked = array_intersect_key(self::byName(self::PICKED), array_flip($this->enabled()));
		foreach (self::byName(self::ALWAYS) + $picked as $name => $class) {
			$filename = __DIR__ . "/available/$name.php";
			// A name a later adminer release no longer ships is skipped rather than fatal:
			// this runs before there is any UI to untick it with.
			if (is_file($filename)) {
				include_once $filename;
				$return[] = new $class(...(self::ARGUMENTS[$class] ?? []));
			}
		}
		return $return;
	}

	/** Each plugin's one-line description, in the interface language where it has one.
	*
	* Every shipped plugin carries its own translations, so this needs no network and cannot
	* go stale against the bundled version — all of them have Czech.
	*
	* The files are included but only the enabled ones were instantiated: reflection reads
	* the default value of $translations off the class. Including the rest is safe because
	* adminer builds its plugin list in Plugins::__construct, which has long since run by
	* the time a page renders, so a class declared now is not picked up and enabled. It is
	* the same path instances() includes, which is what keeps include_once meaning once —
	* the same class reached through two paths is a fatal redeclare, not a no-op.
	* @return array<string, string>
	*/
	function descriptions(): array {
		$return = [];
		foreach (self::byName(self::PICKED) as $name => $class) {
			$filename = __DIR__ . "/available/$name.php";
			if (!is_file($filename)) {
				continue;
			}
			include_once $filename;
			$description = "";
			if (class_exists($class, false)) {
				$defaults = (new \ReflectionClass($class))->getDefaultProperties();
				$translations = (isset($defaults["translations"]) ? (array) $defaults["translations"] : []);
				$description = (string) ($translations[\Adminer\LANG][""] ?? "");
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

	/** Render the plugin panel. The markup is plugins-panel.latte. */
	function panel(): void {
		$enabled = array_flip($this->enabled());
		$plugins = [];
		foreach ($this->descriptions() as $name => $description) {
			$plugins[$name] = [
				// A plugin name is a filename, and an id has to be usable in a selector.
				"id" => "desktop-plugin-" . preg_replace('~[^\w-]~', "-", $name),
				"enabled" => isset($enabled[$name]),
				"description" => $description,
			];
		}
		Latte::engine()->render(__DIR__ . "/plugins-panel.latte", [
			"desktop" => $this->desktop,
			"plugins" => $plugins,
		]);
	}

	/** Store what the dialog says about every plugin, keeping only the answers that differ
	* from what we ship on by default.
	*/
	function apply(): void {
		// Whitelist by construction: we walk the catalogue and only ask whether each name of
		// ours was posted, so nothing user-supplied is ever stored or reaches an include path.
		// ?? []: unticking the last plugin posts no plugins[] at all, which is the case that
		// has to turn them off, not be mistaken for "nothing was submitted".
		$posted = array_flip((array) ($_POST["plugins"] ?? []));
		$default = self::byName(self::DEFAULT_ON);
		$answers = [];
		foreach (self::names() as $name) {
			$on = isset($posted[$name]);
			if ($on !== isset($default[$name])) {
				$answers[$name] = $on;
			}
		}
		$this->settings->set(SettingKey::Plugins, $answers);
	}
}
