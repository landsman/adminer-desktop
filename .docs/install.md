# Installing

Downloads are on the [releases page](https://github.com/landsman/adminer-desktop/releases) —
beta, so they are marked pre-release. PHP is inside the app; there is nothing else to install.

## macOS

The app is unsigned, so macOS blocks it on first launch.

```sh
unzip adminer-desktop_0.4_macos-arm64.zip
mv "Adminer Desktop.app" /Applications/
xattr -dr com.apple.quarantine "/Applications/Adminer Desktop.app"
open "/Applications/Adminer Desktop.app"
```

Without the terminal: double-click it, let it be blocked, then **System Settings → Privacy &
Security → Open Anyway**. On macOS 15 and newer the old right-click → Open shortcut no longer
works for unsigned apps.

Signing it properly needs a paid Apple Developer account.

## Debian / Ubuntu

```sh
sudo apt install ./adminer-desktop_0.4_amd64.deb
```

`apt` pulls in GTK and WebKit; the app then appears in the launcher. Upgrading means
downloading the next `.deb` — there is no repository to subscribe to, and
[releases-linux.md](releases-linux.md) says why not.

## Platforms

| | |
| --- | --- |
| macOS, Apple Silicon | works |
| Linux x86_64 | works (`.deb`, or `make tarball`) |
| Windows | builds, but CI is red — not usable yet |

## Where it keeps your things

Preferences and the key that keeps you logged in sit in the OS's own config directory —
Application Support on macOS, `~/.config` on Linux — so they survive an upgrade. Logs are
alongside them (`~/Library/Logs/Adminer Desktop/` on macOS), and **Open Logs** in the menu
opens the folder.

Nothing else is written anywhere: no daemon, no login item, no port left listening once the
window is closed.
