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
make qa      # php lint, phpstan, golangci-lint, shellcheck, gofmt, go vet
make check   # asserts the transport, and that settings apply before login (~2 min)
```

Both pass locally before a push. Tools not installed here — shellcheck, semgrep — run
through Docker rather than being skipped.

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
script. Inline scripts must follow the element they bind to.

**Adminer's own CSS is the styling system.** Reuse `--bg`, `--fg`, `--dim`, `--lit` and
classes like `.odds`; `dark.css` overrides them, so anything built on them follows the
design the user picked. Our own tokens are prefixed `--ad-`.

## Layout

```
app/desktop.php              the plugin adminer sees: hooks and all translations
app/files.php                Desktop\Files - recursive file finding
app/settings/dialog.php      the settings dialog shell
app/settings/theme/          designs, previews, the screenshot endpoint
app/settings/plugins/        the catalogue and the enable/disable logic
app/styles/styles.php        loads the CSS in app/styles/css/
app/desktop/javascript.php   loads app/desktop/javascript/ - JS that closes WebView/browser gaps
```

Everything downloaded — `adminer.php`, `editor.php`, the catalogue, the designs — is
pinned in the `Makefile` and checksum-verified. Nothing resolves "latest", and those
files are never edited: behaviour changes go in the plugin.

## Conventions

Adminer's, because this code sits next to Adminer's: tabs, `h()` for HTML, `lang()` with
single quotes, bare `$_POST["key"]`, `{}` around every block. `make qa` enforces the
mechanical ones.

Commit messages say why, not what. No Claude or AI attribution anywhere — not in
commits, PR text, comments or docs.
