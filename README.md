# adminer-desktop

[Adminer](https://www.adminer.org) as a desktop app. Download it, open it, connect, and start
working. Everything you need is included, so there's nothing to install, configure, or keep
running in the background.

Adminer itself is not modified or forked. This app runs the official Adminer release in a
native desktop window and adds a few desktop-friendly improvements around it. Not affiliated
with the Adminer project.

## What it adds

- Install it like any other app. No PHP, no web server, no browser.
- Works well with Docker. Sensible defaults make connecting to containerized databases easier.
- Stays logged in between launches.
- Settings are available before you connect.
- Light and dark mode, with themes for both.
- Language, scaling, row density, and plugins are all in one place.
- Choose the plugins you want. Your selection is remembered.
- Reset everything back to the default settings with one click.
- Resizable sidebar that remembers its width.
- Resizable table columns for easier browsing.
- Larger edit forms that remember your layout.
- Double-click a table to open its data.
- Drag and drop `.sql` files onto the import page.
- Exports download as files, with progress.
- Confirmation dialogs before destructive actions.
- Desktop navigation with Back/Forward buttons, trackpad gestures, Cmd/Ctrl+R, and F5.
- Browse tables without losing your place in the sidebar.
- Sorting and resizing refresh the rows in place, without the page rebuilding itself.

## Documentation

- **[Installing](.docs/install.md)** — macOS, Debian/Ubuntu, which platforms work, and where
  it keeps your settings.
- **[Developing](.docs/development.md)** — building it yourself, the checks, what is pinned.
- **[Linux releases](.docs/releases-linux.md)** — why there is a `.deb` and no apt repository.

## Licence

Adminer is Apache-2.0 / GPL-2.0. This wrapper is MIT. The Adminer logo, used as the app icon,
belongs to the Adminer project.
