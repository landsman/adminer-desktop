# adminer-desktop

[Adminer](https://www.adminer.org) as a desktop app. No PHP install, no web server, no
browser tab — a native window with your databases in it.

Adminer is neither modified nor forked: this downloads the released `adminer.php` at a pinned
version, verifies its checksum, and runs it in that window. Everything below is added around
it. Not affiliated with the Adminer project.

## What it adds

**It behaves like an app, not a page**

- Opens in its own window. The server behind it starts with the app, listens on a random
  local port, and stops when you close it — nothing is left running.
- Mouse back and forward buttons, and the trackpad swipe, move through history.
- `Cmd`/`Ctrl+R` and `F5` reload.
- "Are you sure?" actually asks. In a bare WebView those never appear, and dropping a table
  went ahead silently.
- Exports save as real downloads, with progress, instead of the window trying to display a
  dump.
- Right-clicking a link offers nothing about new windows or bookmarks; right-clicking a value
  still copies it.
- A menu bar, and Open Logs in it.

**Less setup before you see data**

- The server field starts at `127.0.0.1`. Adminer's blank default means a Unix socket, which a
  database in Docker never has.
- Stay logged in across restarts — the key that permits it lives somewhere the OS does not
  clear out.

**A layout for a window, not a browser tab**

- Sidebar and content are two panels: drag the divider to the width you want, and it opens
  there next time.
- The table list keeps its scroll position instead of jumping to the top on every click.
- Double-click a table to open its data, single-click for its structure.

**Reading what is in the tables**

- Drag any column's edge in the data list to set its width — down the whole column, not just
  the header — and the values are re-fetched at the length the new width can show, in place,
  without the page reloading.
- Those widths last as long as the window: they follow the query in front of you, not your
  settings.
- Edit forms open at a width worth typing in, and each field stays where you drag it. Adminer
  sizes every field the same regardless of what is in it, which is thin for a JSON payload.

**Importing**

- Drop a `.sql` file anywhere on the import page rather than hunting for the file input.

**Settings, from a gear button — before you log in, unlike upstream**

- **Appearance** — follow the system light and dark, or pin it to one.
- **Design** — pick one for light and one for dark, from Adminer's own gallery or the theme
  this app ships.
- **Row density and scaling** — for a laptop screen or a monitor across the desk.
- **Language** — Adminer's own switch, moved somewhere findable.
- **Plugins** — the upstream ones that make sense on a desktop, hand-picked. Adminer ships 51;
  the rest want a reverse proxy, an MTA or a CDN, or duplicate something this app already
  does. What you tick is remembered, including what you turn off.
- **Reset to defaults** — one button, rather than finding and deleting a JSON file.

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
