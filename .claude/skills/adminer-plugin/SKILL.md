---
name: adminer-plugin
description: Write, extend or debug an Adminer plugin in this repo — the Adminer\Plugin base class, the 79 hooks on Adminer\Adminer, hook dispatch semantics (null = abstain, first non-null wins), the Adminer\ function surface, and how plugins are registered here. Use when adding a plugin under app/src/Settings/Plugins/available/, changing app/AdminerDesktop.php, picking a hook to override, wondering why a hook never fires or why another plugin wins, or working with Adminer\ functions like h(), q(), idf_escape(), get_rows(), support().
---

# Writing Adminer plugins

A plugin is one class extending `Adminer\Plugin` that overrides some of the 79 hooks
on `Adminer\Adminer`. Adminer calls every registered plugin's hook in turn and
combines the answers. Almost every mistake in plugin authoring is a
misunderstanding of *how* it combines them — so read the dispatch rules first.

Facts below were extracted from the pinned `app/adminer.php` (Adminer 5.5.1) and the
~50 upstream plugins in `app/src/Settings/Plugins/available/`. The compiled adminer
is minified, so line-based `grep "function foo("` returns nothing — declarations wrap
across lines. Flatten first: `tr '\n' ' ' < app/adminer.php | grep -oE "function +foo *\([^)]*\)"`.

## Hook dispatch — the one thing to get right

Adminer routes every hook through `Plugins::__call`, which decodes to:

```php
function __call($name, array $args) {
	$refs = [];
	foreach ($args as $k => $v) { $refs[] = &$args[$k]; }   // by reference
	$result = null;
	foreach ($this->hooks[$name] as $plugin) {
		$r = call_user_func_array([$plugin, $name], $refs);
		if ($r !== null) {
			if (!self::$append[$name]) { return $r; }        // first non-null WINS, stops
			$result = $r + (array) $result;                   // append mode: array union
		}
	}
	return $result;
}
```

Four consequences, in order of how often they bite:

1. **`return null` means "no opinion, ask the next plugin".** It is not "return
   nothing". A hook that falls through must return `null`, never `''`/`false`/`[]` —
   those are values and they end the chain. `AdminerDesktop::loginFormField()` is the
   model: it rewrites only the `server` field and returns `null` for every other one,
   so adminer renders the rest.

2. **First non-null answer wins and short-circuits.** Later plugins are never called
   for that hook on that request. If two plugins both claim `login()`, the earlier one
   decides and the second is silently dead. Order is the order of the array returned
   from `app/adminer-plugins.php` — and that array is
   `array_merge([$desktop], $desktop->plugins()->instances())`, so **`AdminerDesktop`
   runs before every user-enabled plugin and wins any hook it answers.**

3. **Exactly five hooks accumulate instead of short-circuiting** — `dumpFormat`,
   `dumpOutput`, `editRowPrint`, `editFunctions`, `config`. These merge with PHP's `+`
   (`$current + $accumulated`), so keys from every plugin survive and, on a key
   collision, the plugin evaluated *later* wins. This is how several dump-format
   plugins coexist in one `<select>`.

4. **Arguments are passed by reference.** A hook can mutate an argument and both the
   later plugins and adminer itself see the change. Some hooks are designed around
   this — you mutate the array and return `null`, rather than returning a value.

Print-style hooks (`selectLinks`, `tablesPrint`, `navigation`, …) are the same
machinery: `echo` your markup and return non-null to suppress adminer's own output,
or return `null` to let adminer print as usual after you.

## Where plugins live here

Two routes exist. This repo deliberately uses only the second.

| Route | How adminer finds it | Used here |
|---|---|---|
| `adminer-plugins/` directory next to the doc root | globbed, every file included and **auto-enabled**, constructor args impossible | **Never** — see the trap below |
| `adminer-plugins.php` returning instances | you construct each one and control the list | Yes |

- **`app/adminer-plugins.php`** — the entry adminer includes. Boots the autoloader,
  requires `AdminerDesktop.php`, calls `handlePost()`, returns
  `array_merge([$desktop], $desktop->plugins()->instances())`.
- **`app/src/Settings/Plugins/available/`** — the downloaded upstream catalogue,
  checksum-pinned in the `Makefile`. **Never edit these files**; behaviour changes go
  in our own plugin.
- **`app/src/Settings/Plugins/PluginList.php`** — `PICKED` maps the file to the class
  it declares and is the hand-picked list of what the app offers; `DEFAULT_ON` is what
  a fresh install enables; `ARGUMENTS` supplies constructor arguments.
- **`app/AdminerDesktop.php`** — our own always-on plugin, global namespace on purpose.

Adding an upstream plugin to the offer = one line in `PICKED` + confirming it boots
(`make check` constructs every picked plugin in turn).

## Anatomy

```php
class AdminerThing extends Adminer\Plugin {
	/** @var string */
	private $suffix;

	function __construct($suffix = '') {   // args only reachable via PluginList::ARGUMENTS
		$this->suffix = $suffix;
	}

	function head($dark = null) {          // a hook: see reference/hooks.md
		echo Adminer\script_src('thing.js');
		return null;                        // abstain — let other plugins and adminer run
	}

	function screenshot() {
		return 'https://www.adminer.org/static/plugins/thing.png';
	}

	/** The '' key is the plugin's own description; the rest are message translations.
	* @var array<string,array<string,string>> */
	protected $translations = [
		'cs' => ['' => 'Popis pluginu', 'Ask' => 'Zeptat se'],
	];
}
```

`Adminer\Plugin` itself provides only three methods: `description()` (reads
`$translations[LANG]['']`), `screenshot()`, and `lang($message, $number = null)`
(translates via `$translations`). Everything else you write is a hook override.

## Traps

**Anything named `Adminer*` becomes a plugin.** Adminer registers every declared class
whose name starts with `Adminer`. Helper classes go in the `Desktop\` namespace for
exactly this reason — a stray `class AdminerHelper` gets instantiated and hooked.

**Never create `app/adminer-plugins/`.** That directory name is globbed by adminer and
every file in it is included and enabled behind the app's back, with no way to pass
constructor arguments — plus a fatal redeclare the moment the same class also arrives
through `adminer-plugins.php`.

**`lang()` runs its argument through sprintf.** A `%d` intended for JavaScript is
replaced before the browser sees it. Use `{n}` and substitute in JS.

**`qsl()` returns the last match in the whole document**, not the element before the
script. Upstream plugins rely on an inline `<script>` immediately following the element
— which works, but is not this repo's style: our scripts are files under
`app/src/Assets/javascript/` bound by `id`.

**Driver-dependent functions.** `idf_escape`, `fields`, `tables_list`, `create_sql`,
`support`, `get_databases`, `is_view` and `table` each have ~5 definitions in
adminer.php, one per driver; which is live depends on the connection. Feature-test with
`Adminer\support('sql')` rather than assuming, and remember a plugin may run against
MySQL, PostgreSQL, SQLite, MS SQL or Oracle.

**Hooks that need a connection don't fire on the login page.** `afterConnect()` and
anything downstream never runs when you are not connected — which is why design
switching lives in `AdminerDesktop`/`adminer-plugins.php` (included from
`bootstrap.inc.php` after `session_start()` and before output) rather than in a hook.

## Choosing a hook

Ranked by how often the upstream catalogue overrides them — a good proxy for "this is
the intended seam":

`editInput` (9) · `head` (7) · `login` (6) · `dumpHeaders` (5) · `dumpTable`,
`dumpFormat`, `dumpFooter`, `dumpData`, `credentials` (4) · `syntaxHighlighting`,
`loginFormField`, `headers`, `config` (3) · `tablesPrint`, `tableName`, `selectVal`,
`processInput`, `navigation`, `messageQuery`, `dumpOutput`, `css`, `afterConnect` (2)

Full list with signatures and grouping: **`reference/hooks.md`**.
Function surface with signatures: **`reference/functions.md`**.

To intercept a request wholesale and emit a non-HTML body, override `headers()`, write
your output and `exit` — this is how `sql-gemini.php` answers its own AJAX POST. Note
`headers()` fires early, before adminer has produced a page.

## Working in this repo

Our own code is PSR-4 `Desktop\` under `app/src/`, native types on properties, `[]` not
`array()`, enums over magic strings. The downloaded catalogue in `available/` is
**excluded** from phpstan and phpcs (`phpstan.neon`, `phpcs.xml`) — so upstream's
untyped style there is not a signal for code you write. A hook overriding an untyped
adminer method keeps adminer's shape (phpdoc `@param`/`@return`, or a `phpcs:ignore`),
because PHP forbids narrowing an inherited signature.

Verify:

```sh
make qa       # php lint, phpstan level 6, phpcs, golangci-lint, biome, shellcheck
make check    # boots the app, constructs every picked plugin, asserts before-login behaviour
make e2e      # browser checks (needs docker); tests/e2e/plugins/ is one file per plugin
make debug    # run with Safari Web Inspector attached — use this the moment a symptom is visual
```

`make check` only proves a plugin *boots*. Anything a plugin does to a form is only
ever asserted by a `tests/e2e/plugins/<name>.test.php` — drop the file in and it runs,
there is no list to update.
