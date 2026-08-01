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

## Documentation

- **[Installing](.docs/install.md)** — macOS, Debian/Ubuntu, which platforms work, and where
  it keeps your settings.
- **[Developing](.docs/development.md)** — building it yourself, the checks, what is pinned.
- **[Linux releases](.docs/releases-linux.md)** — why there is a `.deb` and no apt repository.

## Licence

Adminer is Apache-2.0 / GPL-2.0. This wrapper is MIT. The Adminer logo, used as the app icon,
belongs to the Adminer project.
