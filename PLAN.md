# adminer-desktop — implementation plan

Adminer as a double-clickable desktop app on macOS / Windows / Linux.
No PHP install, no web server setup, no browser tab.

## What this is not

Not a rewrite. We consume the released `adminer-X.Y.Z.php` artifact **verbatim** —
35k lines of accumulated SQL-dialect knowledge stay where they are. This repo is a
launcher and a packaging job. If it ever needs to patch Adminer's behaviour, it does
so through the existing plugin system, never by forking the source.

## Adminer version is pinned

Builds pin an **exact** Adminer tag. Never `releases/latest` — a build run twice a
week apart must produce the same thing.

One variable, one place, `Makefile`:

```make
ADMINER_VERSION = 5.5.1
```

`make app/adminer.php` downloads that exact tag and verifies it against
`adminer.php.sha256` in the repo. Checksum mismatch = hard fail, not a warning: it
means either the release was re-uploaded or the download was tampered with, and
neither is something to shrug at.

Bumping Adminer is a one-line commit plus a new checksum — reviewable in a diff.

Skipped: a version manager, multi-version support, an auto-update checker.

## Architecture

```
Adminer.app/Contents/MacOS/
  adminer-desktop      <- Go: starts server, opens window, cleans up
  frankenphp           <- PHP runtime + Caddy, one file
                       <- no config file; php-server flags cover it
  app/adminer.php      <- the released artifact, embedded or bundled
  app/adminer-plugins/ <- desktop-mode plugin (M2)
```

One process tree, one window. On window close the server dies with it.

### Why not a "single binary"

FrankenPHP can produce a fully static single binary, but it needs a Docker
static-builder and a from-source PHP. A macOS `.app` **is a directory**, a Windows
install **is a folder**, an AppImage **is a squashfs**. Every target already lets you
ship more than one file. Single-binary is a vanity requirement that costs a whole
build pipeline. Skipped — see M4 if it ever actually matters.

## Known risks (found by reading adminer source, before writing any code)

PHP-layer timeouts are **already handled upstream** — nothing for us to do:

| Handled | Where |
| --- | --- |
| `set_time_limit(0)` | `adminer/include/bootstrap.inc.php:63` |
| memory_limit raised per query | `adminer/sql.inc.php:45` |
| XHR sets no `.timeout` (browser default: never) | `adminer/static/functions.js:564` |

The risks are all in the Caddy layer FrankenPHP brings, which stock Adminer never met.
FrankenPHP **is** Caddy — the binary reports `FrankenPHP v1.12.6 PHP 8.5.8 Caddy v2.11.4` —
so this is configuring something we already have, not an added dependency:

| Risk | Why it bites | Status |
| --- | --- | --- |
| Caddy `write` timeout | Cuts long dumps / queries mid-response | no timeout by default — **verified by M0** |
| Response buffering | Adminer streams via `ob_flush(); flush()` (`sql.inc.php:132`, `dump.inc.php:121`, `functions.inc.php:667`). Buffered = whole dump in RAM | not buffered — **verified by M0**, first byte at 0s |
| Compression | `file.inc.php:14` already sets `zlib.output_compression` → double compress | `--no-compress` |
| `num_threads 1` | `functions.inc.php:888` opens a **second** PHP request to `KILL` a running query. One thread → it queues behind the query it is killing. Kill button silently does nothing. | defaults to 2×CPU, so ≥2 even on a single-core box |

### There is no Caddyfile

```sh
frankenphp php-server --root app --listen 127.0.0.1:PORT --no-compress
```

That alone gives every row above, plus plaintext HTTP (Caddy only switches to HTTPS
when `--domain` is passed) and a real localhost-only bind.

A hand-written Caddyfile was tried first and was strictly worse. It needed an explicit
`http://` or Caddy served HTTPS behind a self-signed cert, and an explicit
`bind 127.0.0.1` because a site address is only Host-header matching — `lsof` showed it
listening on `*:18000`, precisely the LAN exposure the config existed to prevent.
Deleted.

This does mean relying on Caddy's *defaults* instead of pinning timeouts explicitly.
That is safe here for a specific reason: the FrankenPHP version is pinned and
`make check` asserts the behaviour, so a default cannot change under us without the
gate going red.

---

## M0 — verify the transport

No app code. Answers "does FrankenPHP break Adminer?" before anything is built on it.

No app code. Answers "does FrankenPHP break Adminer?" before anything is built on it.

1. Download the pinned `frankenphp-mac-arm64` release binary. (Not brew — the tap needs
   an explicit trust step, and we need the portable binary for the `.app` anyway.)
2. `make fetch` — adminer, editor, plugins, designs, all at the pinned tag.
3. `app/_stream.php` — a 120s progressive-flush loop mimicking how Adminer streams.
4. `make check` — assert 24 lines, arriving progressively, ~120s, connection not cut.

**Status: PASS.** `got 24/24 lines in 120s, first byte at 0s`. Adminer and Editor both
return 200 on PHP 8.5.8 with no deprecation output, and the designs dropdown renders.

**Deferred:** run a genuinely slow query against a real DB and confirm the kill button
works. Needs a live MySQL, so it can't live in `make check`. Deferred rather than
dropped because the measured 36 threads make failure unlikely, and if it does fail the
fix is a thread-count flag — not an architecture change. Nothing in M1 depends on it.

## M1 — the app

`main.go`, target ~60 lines:

- `go:embed app/*` → extract to a cache dir on first run (or run in place during dev)
- listen on `:0`, take the OS-assigned port
- `exec.Command(frankenphp, "php-server", "--root", dir, "--listen", addr, "--no-compress")`, wire stderr to our log
- `webview.New()` → `Navigate("http://127.0.0.1:<port>/adminer.php")`
- `defer cmd.Process.Kill()` + signal handling so no orphan server survives

Deps: `github.com/webview/webview_go` only. cgo, but macOS CLT is already installed.

**Done when:** `go run .` opens a window, connects to a DB, dumps a large table without truncating.
**Fallback if webview fights us:** `open http://127.0.0.1:<port>` — the OS default browser. Loses the window, keeps the product. Decide at the time, don't pre-build both.

## M2 — what we ship inside the app

### Adminer *and* Editor

Both are separate compiled artifacts of the same tag: `adminer-5.5.1.php` and
`editor-5.5.1.php`. Both ship, both are served, both see the same
`adminer-plugins/` folder because they sit in the same directory.

No mode-picker UI gets built. They are two complete apps at two URLs; the launcher
opens Adminer by default and a native menu item switches to Editor.

### Designs (26 of them)

Also in the same pinned zip. The 26 downloaded designs are the gallery; upstream's
`designs` plugin showed the idea. This has since grown its own theme UI — the settings
dialog (`app/settings/theme/`) with a light/dark picker and a row-density control — and a
default theme of ours, `adminer-desktop`, that ships alongside the gallery and is what you
get out of the box. See CLAUDE.md, "The Adminer Desktop theme".

Not downloaded on demand. Downloading later means writing fetch code, cache
invalidation, and network error handling — strictly more work than shipping a few
hundred KB of CSS next to a 178 MB runtime, and it breaks offline.

### Plugins: shipped, off by default, individually toggleable

62 plugins ship. **None are enabled by default.** Enabling everything is wrong on the
merits: three plugins are rival syntax highlighters, several need constructor
arguments, and a default set is a taste decision that generates support burden.

The mechanism is forced by how Adminer discovers plugins
(`adminer/include/plugins.inc.php:17-42`): every file in `adminer-plugins/` is
included *and auto-instantiated*. There is no "installed but off". So:

```
app/plugins-available/   62 files, shipped, never loaded  -> the catalogue
app/adminer-plugins/     the enabled ones                 -> the state
app/adminer-plugins.php  the ones needing constructor args (designs)
```

**The enabled set is the directory contents.** No config file, no registry to keep in
sync — and a user who drags a downloaded plugin into the folder by hand gets exactly
the same result as one who ticks a box.

The toggle UI hooks `pluginsLinks()`, which Adminer already calls right below its own
"Loaded plugins" list (`adminer/include/connect.inc.php:91-111`). Checkbox per
plugin, POST toggles a symlink. No new page, no new route, no marketplace.

Known gap: plugins with required constructor arguments can't be enabled by a tick —
Adminer responds with its own "Configure X in adminer-plugins.php" message. Left as
is for M2; revisit only if it bites in practice.

### Desktop behaviour

Skip the login screen, open a `.sqlite` path from `argv`, remember connections.
Same mechanism — one plugin of ours, zero changes to `adminer.php`.

**Prefill Server with `127.0.0.1`.** Adminer leaves it empty, which means "connect over
a Unix socket". On a desktop Mac the database is nearly always in Docker or remote, and
Docker publishes TCP only — it never creates a socket. So the stock default fails with
`connection to server on socket "/tmp/.s.PGSQL.5432" failed: No such file or directory`
while the server is running perfectly well on `127.0.0.1:5432`. Hit during M1 testing.
Correct default for a server deployment, wrong one for a desktop app.

**Done when:** plugins toggle on and off from the UI, the design dropdown works,
Editor is reachable, and launching with a `.sqlite` argument opens that database.

## M3 — package for macOS ARM

**First build and first release: macOS, Apple Silicon only.** Nothing else is built,
nothing else is tested, and no cross-platform abstraction gets written in advance for
platforms that have no release date. The Makefile pins `frankenphp-mac-arm64` and that
is the whole matrix.

- `.app` bundle: `Info.plist` + the tree above, zipped
- Intel mac, Linux and Windows are one extra `FRANKEN_ASSET` value and a build run
  each — added when someone actually asks, not before

**Done when:** a colleague with an M-series Mac unzips it, double-clicks, and it works.

## M4 — deferred, on purpose

Code signing, macOS notarization (needs an Apple Developer cert as a CI secret),
Windows SmartScreen, auto-update, a 3-OS release matrix, static single-binary build.

Each of these is real work with real recurring cost. **Add when someone other than us
is actually installing it** — not before. Until then a zip and a "right-click → Open"
note in the README is enough.

---

## Milestone order is the point

M0 is the only milestone that can invalidate the others. It runs first and it costs
an hour. Everything after it is packaging.

---

## Later: a template engine for the HTML

The settings dialog is built by `echo`-ing HTML out of PHP, which is how Adminer itself
writes UI and why it was written that way here. It has stopped scaling: `navigation()`
is now tabs, two tables, previews and an actions row, all as escaped string
concatenation, and every change means counting quotes.

Worth moving to a real template engine — [Latte](https://latte.nette.org) is the
obvious candidate: context-aware escaping, so `h()` around every value stops being
something to remember, and the markup becomes readable as markup.

Not done yet because it is the first runtime dependency this project would take on, and
it needs answers first: vendored into `app/` or fetched at build time and checksummed
like everything else? Compiled templates cached where — the data directory, next to the
screenshot cache? And it only earns its place once there is more UI than one dialog.

The CSS already moved out to `app/styles/` for the same reason. HTML is the half that
is left.
