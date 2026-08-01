# CLAUDE.md

Guidance for Claude Code when working in this repository.

## Debug the running app, do not guess

`curl` against the dev server only proves what HTML was generated. It says nothing about
rendering, CSS or JavaScript, and three separate bugs here were invisible to it: a
`<dialog>` that never closed, a sticky header rows painted over, and a `confirm()` that
never fired.

```sh
make debug          # or ./build/adminer-desktop -debug
```

That turns on **Safari's Web Inspector** against the app's page — Safari → Develop →
this machine → Adminer Desktop — giving a real console, the DOM, network and
breakpoints. It also logs diagnostics at startup (which UI delegate is attached, whether
the inspector came up).

Reach for it as soon as a symptom is visual or behavioural. `uiDelegate=(nil)` in that
log found in one line what two rounds of guessing had missed.

Never enable the inspector outside `-debug`.

PHP fatal errors reach `~/Library/Logs/Adminer Desktop/adminer-desktop.log`, but only
because `app/php/desktop.ini` turns `log_errors` on — frankenphp's default sends them to
the page and nowhere else. `make logs` or the Open Logs menu item opens the folder.

**What the api did is in `<data dir>/log/api.log`**, under `-debug` only: `app/api.php`
writes the action, the status and what was posted, one line per request. Tracy's bar cannot
help there — the answer is a 204 to a `sendBeacon` fired as the page is being torn down, so
there is no page left to render a bar on, and a preference that silently failed to save looks
exactly like one that saved.

## Verify before pushing

GitHub Actions is billed on this private repo and macOS runners cost 10x, so CI is not a
test harness to iterate against. The three-platform build only runs on manual dispatch.

```sh
mise trust         # once per clone: mise.toml carries a [settings] block
gh auth login      # once per machine: make verify reads frankenphp's attestation over the API
mise run install   # once: node deps, composer deps, and the e2e browser
make qa            # php lint, phpstan, golangci-lint, biome, shellcheck, gofmt, go vet
make verify        # adminer checksums + proof frankenphp is the binary upstream built
make check         # boots the app; asserts the plugin's before-login behaviour (prefill, design, plugins)
make e2e           # browser check: logs in, asserts the theme light and dark (needs docker)
```

`qa` and `check` pass locally before a push. Tools not installed here — shellcheck,
semgrep — run through Docker rather than being skipped.

## Things that will bite

**Anything named `Adminer*` becomes a plugin.** Adminer instantiates every declared class
whose name starts with `Adminer` and registers it (`include/plugins.inc.php:33`). Helper
classes live in the `Desktop\` namespace for that reason.

**Never create `app/adminer-plugins/`.** A directory by that name at the document root is
globbed by adminer, and every file in it is included and enabled behind the app's back
(`include/plugins.inc.php:17-19`) — with no way to pass constructor arguments, and a fatal
redeclare the moment we include the same class from the catalogue. We hand adminer the
instances instead: `PluginList::instances()`, returned from `adminer-plugins.php`.

**Which plugins ship is a hand-picked list, not a glob.** `PluginList::PICKED` maps the
file in `src/Settings/Plugins/available/` to the class it declares; the whole upstream set
is downloaded but only these are offered. Adding one means checking it works here — nine of
upstream's 51 cannot even be constructed without arguments, and others want a reverse proxy,
an MTA or a CDN. `make check` boots every picked plugin in turn, which is what says so.
`PluginList::DEFAULT_ON` is what a fresh install has enabled; `settings.json`
(`SettingKey::Plugins`) stores the user's answer per plugin, `name => on`, and only where it
differs from that — so adding a default reaches everyone who never had an opinion, and never
overrides someone who turned it off. No version alongside the names: the plugins come out of
the adminer release pinned in the Makefile.

**`lang()` runs strings through sprintf.** A `%d` meant for JavaScript is replaced with 0
before the browser sees it. Use `{n}`.

**`qsl()` returns the last match in the whole document**, not the element before the
script. Inline scripts must follow the element they bind to — which is why ours are files
under `src/Assets/javascript/` bound by id instead.

**Our HTML is Latte, adminer's is adminer's.** `Desktop\Latte::engine()` builds the engine;
templates sit beside the class that renders them and escape by context, so no `h()`.
Adminer's `input_hidden()`/`input_token()` are registered on it, and `make qa` compiles
every `.latte` through that same engine. An `n:attribute` needs its element closed, which
adminer's markup often is not.

**Adminer's own CSS is the styling system.** Reuse `--bg`, `--fg`, `--dim`, `--lit` and
classes like `.odds`; `dark.css` overrides them, so anything built on them follows the
design the user picked. Our own tokens are prefixed `--ad-`.

**composer's `vendor/` lives in `app/`, not the repo root.** Two reasons in one move. It
keeps out of Go's way — a `vendor/` at the module root makes every go command fail with
"inconsistent vendoring", which is why there used to be a `GOFLAGS=-mod=readonly` — and it
puts the autoloader beside the code it maps, so PSR-4 resolves the same in the checkout and
in the packaged app (where `app/` is the document root and there is no root above it).
`composer.json`/`composer.lock` moved there too; run composer with
`--working-dir=app`, as the `app/vendor` target does. We do not vendor Go deps.

**Our code is PSR-4 under `src/`.** `app/composer.json` maps `"Desktop\\": "src/"`, so a
`Desktop\` class loads from the matching path — `Desktop\Settings\Theme\Theme` →
`src/Settings/Theme/Theme.php`, filename and case matching the class. The case match is not
optional: PSR-4 is exact-case so it resolves on a case-sensitive filesystem (Linux, CI) as
well as macOS. The namespace mirrors the folders, so a new class needs no list anywhere —
`composer dump-autoload` (run by `make qa`/the build via `composer install`) picks it up.
Two things sit outside the tree. `AdminerDesktop` is global-namespace on purpose — adminer
registers every `Adminer*` class as a plugin — so it stays at the doc root as
`AdminerDesktop.php`, `require`d (not autoloaded) by `adminer-plugins.php`, which also
`require`s `vendor/autoload.php` to turn the loader on. And the two bare entries that don't
boot adminer — `api.php`, `Settings/Theme/screenshot.php` — each `require`
`vendor/autoload.php` themselves. What used to be free functions is now methods
(`Env::DataDir->get()`, `Latte::engine()`, `Debug::enable()`), so there are no function files
to special-case — PSR-4 covers everything.

## The Adminer Desktop theme

`app/src/Settings/Theme/designs/adminer-desktop/` is the app's own default look, not one of
the downloaded gallery designs. It carries both schemes in one file: every scheme
difference is a `light-dark(light, dark)` token (`tokens.css`), and the used `color-scheme`
picks the side. Adminer sets that from its `<meta name="color-scheme">`, which
`Theme::cssMap()` drives from the appearance preference — `light dark` for Auto (so it
follows the OS), or pinned to one side for a Light/Dark override, which also loads adminer's
own `dark.css` (JUSH palette) when dark. So there is no scheme media query in the theme;
never hardcode `color-scheme` in the CSS either — a value there would beat the meta and
defeat the override. `Theme::designs()` keeps it out of the gallery; empty (the "Adminer
Desktop" row) on each side means "use it".

It reskins through Adminer's own `--bg/--fg/--dim/--lit` plus our `--ad-*`, and is split
into components pulled in by `@import`: `tokens`, `base`, `tables`, `forms`, `sidebar`,
`settings`. `light-dark()` is colour-only, so the one non-colour scheme difference —
inverting adminer's sprite icons on the dark surface — lives in `base.css` and keys off the
`theme-auto`/`theme-dark` body class instead. `system-ui` gives the native OS font with no
branching; the `theme-*`, `os-mac`/`os-windows`/`os-linux` and `density-compact`/`-cozy`/
`-comfortable` body classes come from `AdminerDesktop::bodyClass()` and are the hooks for
appearance, per-OS and per-density tweaks. Biome owns the formatting — one declaration per
line.

## The dev toolchain and e2e

Run every tool through `mise` or `make`, never bare. mise pins the toolchain (go, node), so
`go build` on a machine without go fails with "command not found" while `make qa` and
`mise run <task>` resolve it. The failure to watch for is the other one: a machine that
*does* have its own go or node gets that one, quietly and at the wrong version. Reach for a
`make` target first, and `mise run`/`mise exec` for anything without one.

mise pins node and orchestrates the tooling; run `mise trust` then `mise run install` once
— the trust is what a `[settings]` block in `mise.toml` costs, and that block
(`activate_aggressive`) is what makes the pin actually win over a Homebrew node earlier in
PATH, rather than being silently shadowed by it. `gh` is pinned there too, because
`make verify` needs it: frankenphp publishes no checksums, it publishes GitHub build
provenance, and `gh attestation verify` is what reads that. mise installs it, but only you
can log it in — the check goes over the API, so `mise run install` stops on a logged-out
`gh` rather than letting `verify` fail later. There is no
second PHP — composer and the e2e run on the bundled frankenphp (`./bin/frankenphp
php-cli`), and `.cache/composer.phar` is fetched like `phpstan.phar`. `app/composer.json`
and `package.json` with their lockfiles are the source of truth; `app/vendor/` and
`node_modules/` are built, not committed, and `dg/composer-cleaner` slims `app/vendor/` for
a production build.

`tests/e2e/run.php` is the browser end-to-end check, on playwright-php. It owns its whole
fixture — a throwaway postgres in docker, the app served with a data dir so Adminer's
passwordless block is satisfied — logs in, and asserts the theme applied and the scheme
emulated in light and dark, leaving screenshots in `tests/e2e/screenshots/`. `make e2e`
runs it; it stays out of `qa` because it is slow and needs docker.

Every `*.test.php` there or one folder down is a check — drop one in and it runs, no list to
keep in step. One per surface at the top (`theme`, `settings`, `sidebar-resize`,
`edit-field-width`, `drag-drop-import`), and one per plugin under `plugins/`, which is the
folder that grows:
`check.sh` only proves a plugin boots on the login page, so anything a plugin does to a form
is only ever asserted here. `seed.sql` is applied when the container is **created** —
`make destroy` (not `down`, which leaves the data volume) is what makes a changed seed reach
the database.

## Layout

```
app/adminer-plugins.php      the entry adminer includes: boots the autoloader, returns ours + the enabled plugins
app/AdminerDesktop.php       the plugin adminer sees (global namespace): hooks and all translations
app/api.php                  the one URL the page's own scripts post to: ?action= routes to src/Api/
app/src/                     the Desktop\ namespace, PSR-4: everything we wrote
app/src/Api/                 one class per action; ResizePreference stores a dragged width
app/src/Files.php            Desktop\Files - recursive file finding
app/src/Latte.php            Desktop\Latte::engine() - the engine every *.latte is rendered by
app/src/Debug.php            Desktop\Debug::enable() - Tracy, and only under -debug
app/src/Settings/Dialog.php  the settings dialog shell (settings-dialog.latte)
app/src/Settings/Theme/      designs, previews, the screenshot endpoint
app/src/Settings/Theme/designs/adminer-desktop/   our default theme (@import components)
app/src/Settings/Plugins/    the catalogue and the enable/disable logic
app/src/Assets/              Styles + Javascript, and the css/ and javascript/ they load
launcher/                    the native shell (Go + Objective-C) - see launcher/README.md
launcher/main.go             start frankenphp, point a WebView at it, take it down with the window
launcher/dialogs_darwin.m    what WKWebView leaves out: JS dialogs, file picker, mouse/reload
launcher/download_darwin.m   turn an Export > save attachment into a real download + progress
tests/e2e/run.php            playwright-php browser checks + seed.sql; plugins/ is one file per plugin
mise.toml                    node, and the install/format/lint/e2e tasks
```

Everything downloaded — `adminer.php`, `editor.php`, the catalogue, the gallery designs —
is pinned in the `Makefile` and checksum-verified. Nothing resolves "latest", and those
files are never edited: behaviour changes go in the plugin. The `adminer-desktop` theme
under `src/Settings/Theme/designs/` is the exception — it is ours, and the `.gitignore` negates
the designs-are-downloaded rule to keep it.

## Conventions

Adminer's, because this code sits next to Adminer's: tabs, `h()` for HTML, `lang()` with
single quotes, bare `$_POST["key"]`, `{}` around every block. `make qa` enforces the
mechanical ones.

Where it is our own code and not adminer's, prefer type-safety over plain strings: a native
type on every property (`private ?string $file`, not a bare `private $file` on a `@var`, which
stays only for what the native type can't say — an array's shape), `[]` not `array()`, and an
enum for a fixed set of values (`Desktop\Mode` for the light/dark scheme) rather than a magic
string. `make qa` enforces these: phpstan at level 6 for parameter, return and value types,
and phpcs with slevomat (`phpcs.xml`) for the two things phpstan does not judge — native
property declarations and short array syntax. Both run through the bundled frankenphp, no
separate PHP install. The exceptions are adminer's own downloaded files (excluded) and a
method or property that overrides an untyped one in adminer's base class — those keep
adminer's untyped shape (a phpdoc `@param`/`@return`, or a `phpcs:ignore` on the line),
because PHP forbids narrowing an inherited signature. Class files are PSR-4: a PascalCase
filename matching the class, in folders matching the namespace (`Settings\Theme\Theme` →
`src/Settings/Theme/Theme.php`). Only non-class files stay lowercase — the `.latte` templates,
the `css/`/`javascript/` assets, and the two bare entries served by URL.

Commit messages say why, not what. No Claude or AI attribution anywhere — not in
commits, PR text, comments or docs.
