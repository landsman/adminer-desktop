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

## Verify before pushing

GitHub Actions is billed on this private repo and macOS runners cost 10x, so CI is not a
test harness to iterate against. The three-platform build only runs on manual dispatch.

```sh
mise run install   # once: node deps, composer deps, and the e2e browser
make qa            # php lint, phpstan, golangci-lint, biome, shellcheck, gofmt, go vet
make check         # asserts the transport, and that settings apply before login (~2 min)
make e2e           # browser check: logs in, asserts the theme light and dark (needs docker)
```

`qa` and `check` pass locally before a push. Tools not installed here — shellcheck,
semgrep — run through Docker rather than being skipped.

## Things that will bite

**Anything named `Adminer*` becomes a plugin.** Adminer instantiates every declared class
whose name starts with `Adminer` and registers it (`include/plugins.inc.php:33`). Helper
classes live in the `Desktop\` namespace for that reason.

**`app/adminer-plugins/` cannot move.** Adminer globs for it relative to the document
root and nowhere else. The catalogue under `settings/plugins/available/` is ours; that
directory is not.

**`lang()` runs strings through sprintf.** A `%d` meant for JavaScript is replaced with 0
before the browser sees it. Use `{n}`.

**`qsl()` returns the last match in the whole document**, not the element before the
script. Inline scripts must follow the element they bind to — which is why ours are files
under `desktop/javascript/` bound by id instead.

**Our HTML is Latte, adminer's is adminer's.** `Desktop\latte()` builds the engine;
templates sit beside the class that renders them and escape by context, so no `h()`.
Adminer's `input_hidden()`/`input_token()` are registered on it, and `make qa` compiles
every `.latte` through that same engine. An `n:attribute` needs its element closed, which
adminer's markup often is not.

**Adminer's own CSS is the styling system.** Reuse `--bg`, `--fg`, `--dim`, `--lit` and
classes like `.odds`; `dark.css` overrides them, so anything built on them follows the
design the user picked. Our own tokens are prefixed `--ad-`.

**composer's `vendor/` collides with Go's vendoring directory.** With it present every go
command fails with "inconsistent vendoring"; the Makefile exports `GOFLAGS=-mod=readonly`
to force module mode. We do not vendor Go deps.

## The Adminer Desktop theme

`app/settings/theme/designs/adminer-desktop/` is the app's own default look, not one of
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

mise pins node and orchestrates the tooling; run `mise run install` once. There is no
second PHP — composer and the e2e run on the bundled frankenphp (`./bin/frankenphp
php-cli`), and `.cache/composer.phar` is fetched like `phpstan.phar`. `composer.json` and
`package.json` with their lockfiles are the source of truth; `vendor/` and `node_modules/`
are built, not committed, and `dg/composer-cleaner` slims `vendor/` for a production build.

`tests/e2e/run.php` is the browser end-to-end check, on playwright-php. It owns its whole
fixture — a throwaway postgres in docker, the app served with a data dir so Adminer's
passwordless block is satisfied — logs in, and asserts the theme applied and the scheme
emulated in light and dark, leaving screenshots in `tests/e2e/screenshots/`. `make e2e`
runs it; it stays out of `qa` because it is slow and needs docker.

## Layout

```
app/desktop.php              the plugin adminer sees: hooks and all translations
app/files.php                Desktop\Files - recursive file finding
app/latte.php                Desktop\latte() - the engine every *.latte is rendered by
app/debug.php                Desktop\debug() - Tracy, and only under -debug
app/settings/dialog.php      the settings dialog shell (settings-dialog.latte)
app/settings/theme/          designs, previews, the screenshot endpoint
app/settings/theme/designs/adminer-desktop/   our default theme (@import components)
app/settings/plugins/        the catalogue and the enable/disable logic
app/styles/styles.php        loads the CSS in app/styles/css/
app/desktop/javascript.php   loads app/desktop/javascript/ - JS that closes WebView/browser gaps
tests/e2e/run.php            playwright-php browser check + seed.sql
mise.toml                    node, and the install/format/lint/e2e tasks
```

Everything downloaded — `adminer.php`, `editor.php`, the catalogue, the gallery designs —
is pinned in the `Makefile` and checksum-verified. Nothing resolves "latest", and those
files are never edited: behaviour changes go in the plugin. The `adminer-desktop` theme
under `settings/theme/designs/` is the exception — it is ours, and the `.gitignore` negates
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
because PHP forbids narrowing an inherited signature. Filenames stay lowercase with a
PascalCase class inside (`config.php` → `Config`, like `theme.php` → `Theme`); an IDE may flag
the case mismatch, but it is the house style and PSR-4 is not in play.

Commit messages say why, not what. No Claude or AI attribution anywhere — not in
commits, PR text, comments or docs.
