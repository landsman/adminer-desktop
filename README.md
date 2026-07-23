# adminer-desktop

[Adminer](https://www.adminer.org) as a desktop app. No PHP install, no web server, no
browser tab.

Adminer is neither modified nor forked: this downloads the released `adminer.php` at a
pinned version, verifies its checksum, and runs it in a native window. Not affiliated
with the Adminer project.

## Run

```sh
make run                            # build and open the window
make bundle                         # build/Adminer Desktop.app (icon, menu bar)
make zip                            # ...zipped, to hand to someone else
```

Other targets: `editor`, `qa`, `security`, `check`, `logs`, `serve`, `clean`.

`make check` is the one that matters — Adminer streams dumps with `ob_flush(); flush()`,
so it asserts long responses neither buffer nor time out.

## Platforms

| | |
| --- | --- |
| macOS, Apple Silicon | works |
| Linux x86_64 | works (`make tarball`), needs `libgtk-3`, `libwebkit2gtk-4.1` |
| Windows | builds, but CI is red — not usable yet |

## Installing on another Mac

The app is unsigned, so macOS blocks it on first launch. Terminal route:

```sh
unzip "Adminer Desktop.zip"
mv "Adminer Desktop.app" /Applications/
xattr -dr com.apple.quarantine "/Applications/Adminer Desktop.app"
open "/Applications/Adminer Desktop.app"
```

Without the terminal: double-click it, let it be blocked, then
**System Settings → Privacy & Security → Open Anyway**. On macOS 15 and newer the old
right-click → Open shortcut no longer works for unsigned apps.

Signing it properly needs a paid Apple Developer account.

## Versions are pinned

```make
ADMINER_VERSION    = 5.5.1
FRANKENPHP_VERSION = 1.12.6
```

`adminer.php`, `editor.php`, 51 plugins and 26 designs all come from that one Adminer
tag, so they cannot drift apart. Nothing ever resolves "latest".

## Settings

A gear button, bottom right — works before login, unlike upstream.

- **Plugins** — all 51 ship, none enabled by default. The enabled set *is* the contents
  of `app/adminer-plugins/`, so ticking a box and dropping a file in are the same thing.
- **Theme** — pick a light design and a dark one; the OS setting picks between them.

## Desktop behaviour

One plugin, `app/desktop.php`, no changes to `adminer.php`:

- Server prefilled with `127.0.0.1` — the stock empty value means a Unix socket, which
  a Docker database never has.
- Permanent login survives restarts; upstream keeps its key where macOS deletes it.
- Logs in `~/Library/Logs/Adminer Desktop/`.

See [PLAN.md](PLAN.md) for why any of this is the way it is.

## Licence

Adminer is Apache-2.0 / GPL-2.0. This wrapper is MIT. The Adminer logo, used as the app
icon, belongs to the Adminer project.
