# adminer-desktop

[Adminer](https://www.adminer.org) as a desktop app: download it, open it, connect. PHP comes
inside — there is nothing to install, no web server to run and no browser tab to keep open,
and the database on the other end can be the one in your Docker compose file.

Adminer is neither modified nor forked: this downloads the released `adminer.php` at a pinned
version, verifies its checksum, and runs it in a native window. Everything below is added
around it. Not affiliated with the Adminer project.

## What it adds

- **Install it like an app.** PHP is built in — a `.app` or a `.deb`, no LAMP stack, no
  `php -S`, nothing left running when you close the window.
- **Talks to your Docker databases.** The server field starts at `127.0.0.1`; Adminer's blank
  default means a Unix socket, which a container never has.
- **Stays logged in** across restarts, unlike upstream on macOS.
- **Settings before you log in** — a gear button, where upstream has nothing until you are
  connected.
- **Light and dark that follow the system**, or pinned to one. A design for each, from
  Adminer's gallery or the one this app ships.
- **Row density, scaling and language** in the same place.
- **Plugins you pick from a list** — the upstream ones that make sense on a desktop, and your
  answer is remembered, including what you turn off.
- **Reset to defaults** — one button, rather than finding and deleting a JSON file.
- **Resizable sidebar** that opens at the width you left it.
- **Resizable columns** in the data list: drag any column's edge, anywhere down the column,
  and the values are re-fetched to fill it without the page reloading.
- **Edit forms worth typing in** — wide by default, and each field stays where you drag it.
- **Double-click a table** for its data, single-click for its structure.
- **Drop a `.sql` file anywhere** on the import page.
- **Exports download properly**, with progress, instead of the window trying to display a dump.
- **"Are you sure?" actually asks.** In a bare WebView those never appear, and dropping a table
  went ahead silently.
- **Mouse back and forward**, trackpad swipe, `Cmd`/`Ctrl+R` and `F5`.
- **The table list keeps its place** instead of jumping to the top on every click.

## Installing

### macOS

The app is unsigned, so macOS blocks it on first launch.

```sh
unzip adminer-desktop_0.4_macos-arm64.zip
mv "Adminer Desktop.app" /Applications/
xattr -dr com.apple.quarantine "/Applications/Adminer Desktop.app"
open "/Applications/Adminer Desktop.app"
```

Without the terminal: double-click it, let it be blocked, then **System Settings → Privacy &
Security → Open Anyway**. On macOS 15 and newer the old right-click → Open shortcut no longer
works for unsigned apps. Signing it properly needs a paid Apple Developer account.

### Debian / Ubuntu

Releases carry a `.deb` on the
[releases page](https://github.com/landsman/adminer-desktop/releases) — beta, so they are
marked pre-release.

```sh
sudo apt install ./adminer-desktop_0.4_amd64.deb
```

`apt` pulls in GTK and WebKit; the app then appears in the launcher. Upgrading means
downloading the next `.deb` — there is no repository to subscribe to, and
[.docs/releases-linux.md](.docs/releases-linux.md) says why not.

### Where it keeps things

Preferences and the login key sit in the OS's own config directory — Application Support on
macOS, `~/.config` on Linux — so they survive an upgrade. Logs are alongside them
(`~/Library/Logs/Adminer Desktop/` on macOS), and Open Logs in the menu opens the folder.

## Platforms

| | |
| --- | --- |
| macOS, Apple Silicon | works |
| Linux x86_64 | works (`.deb`, or `make tarball`) |
| Windows | builds, but CI is red — not usable yet |

## Building it yourself

`make install`, then `make run`. The rest — the checks, what is pinned, where things live —
is in [.docs/development.md](.docs/development.md).

## Licence

Adminer is Apache-2.0 / GPL-2.0. This wrapper is MIT. The Adminer logo, used as the app icon,
belongs to the Adminer project.
