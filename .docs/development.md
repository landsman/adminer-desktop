# Developing

Everything in here is the build side of the app. What it does for the person using it is in
[README.md](../README.md).

## One command to set up

```sh
make install
```

The toolchain via mise (node, go, composer and npm deps, the Chromium the browser checks
drive), plus the pinned Adminer and frankenphp downloads. On Linux it also runs
`make linux-deps` — the one step that needs apt, for the GTK/WebKit dev headers the webview
links against.

## Building and running

```sh
make run                            # build and open the window
make bundle                         # build/Adminer Desktop.app (icon, menu bar)
make zip                            # ...zipped, to hand to someone else
```

Other targets: `editor`, `debug`, `demo`, `logs`, `serve`, `tarball`, `deb`, `clean`. `make`
on its own lists them.

## Checking it

```sh
make qa                             # php lint, phpstan, phpcs, golangci-lint, biome, shellcheck
make verify                         # adminer checksums + frankenphp's build provenance
make check                          # boots the app and asserts its behaviour before login
make e2e                            # Behat scenarios against postgres and mysql (needs docker)
```

`make check` is the one that matters most: Adminer streams dumps with `ob_flush(); flush()`,
so it asserts long responses neither buffer nor time out, and it boots every shipped plugin in
turn. `make e2e` runs the Behat scenarios in `tests/e2e/features/` in a real browser — login,
the theme, the settings dialog, the resizable sidebar and columns, drag-and-drop import, the MCP
endpoint — and a failed step leaves the page it failed on in `tests/e2e/artifacts/`.
`make e2e-visual` runs the same in a browser you can watch.

CI is billed on this repo and macOS runners cost ten times the rest, so `qa` and `check` pass
locally before a push rather than being iterated against in Actions.

## Versions are pinned

```make
ADMINER_VERSION    = 6.1.1
FRANKENPHP_VERSION = 1.12.6
```

`adminer.php`, `editor.php`, 49 plugins and 28 designs all come from that one Adminer tag, so
they cannot drift apart. Nothing ever resolves "latest", every download is checksum-verified,
and frankenphp is checked against the build provenance GitHub publishes for it.

Adminer itself is never edited. Behaviour changes go in the plugin the app loads alongside it.

## Where things are

| | |
| --- | --- |
| `app/` | the document root: Adminer, and everything this app adds to it |
| `app/src/` | the `Desktop\` namespace, PSR-4 |
| `launcher/` | the native shell (Go + Objective-C) — see [its README](../launcher/README.md) |
| `tests/e2e/` | the browser checks, one file per surface |
| `.docs/` | this, and [the Linux release story](releases-linux.md) |

[CLAUDE.md](../CLAUDE.md) is the working guide to the codebase: the conventions, and the
things that will bite. [PLAN.md](../PLAN.md) is why any of it is the way it is.
