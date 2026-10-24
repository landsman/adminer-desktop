# Developing

Everything in here is the build side of the app. What it does for the person using it is in
[README.md](../README.md).

## One command to set up

```sh
mise trust                          # once per clone: mise.toml carries a [settings] block
gh auth login                       # once per machine: make verify reads frankenphp's provenance over the API
make install
```

The toolchain via mise (node, go, composer and npm deps, the Chromium the browser checks
drive), plus the pinned Adminer and frankenphp downloads. On Linux it also runs
`make linux-deps` — the one step that needs apt, for the GTK/WebKit dev headers the webview
links against.

## Building and running

```sh
make run                            # build and open the window
make bundle                         # build.noindex/Adminer Desktop.app (icon, menu bar)
make zip                            # ...zipped, to hand to someone else
make dev                            # run, reloading the window on any change under app/
make demo                           # run logged into a seeded postgres (needs docker)
```

Other targets: `editor`, `logs`, `serve`, `down`, `destroy`, `dist`, `tarball`, `deb`,
`windows`, `clean`. `make` on its own lists them.

- `make kill-orphans` stops a server whose window is gone (crash, kill, Cmd+Q), the installed app's too.

## Debugging

```sh
make debug                          # run with Safari's Web Inspector attached
```

- Safari → Develop → this machine → Adminer Desktop: console, DOM, network.
- Use it for anything visual or behavioural; `curl` only shows the generated HTML.
- PHP errors: `~/Library/Logs/Adminer Desktop/` (`make logs`).
- Under `-debug`, `<data dir>/log/api.log` records every call to `api.php`.

## Checking it

```sh
make qa                             # php lint, phpstan, phpcs, golangci-lint, oxfmt, oxlint, shellcheck
make verify                         # adminer checksums + frankenphp's build provenance
make check                          # boots the app and asserts its behaviour before login
make e2e                            # Behat scenarios against postgres and mysql (needs docker)
make e2e-packaged                   # the same, against app/ as a release ships it
```

`make check` is the one that matters most: Adminer streams dumps with `ob_flush(); flush()`,
so it asserts long responses neither buffer nor time out, and it boots every shipped plugin in
turn. `make e2e` runs the Behat scenarios in `tests/e2e/features/` in a real browser:

- A failed step leaves its page in `tests/e2e/artifacts/`.
- The folder decides the database: `data/` runs on both, `shell/` (connections, settings, search,
  shortcuts, MCP) and `pgsql/` on postgres, `mysql/` on mysql.
- `make e2e-visual` shows the browser; `SUITE=mysql` or `ARGS='--name …'` narrows a run.
- A step no context defines fails the run; `make qa` catches one without docker.
- `make e2e-packaged` runs against app/ as a release ships it, so a template the build missed fails.
- [Manual testing](qa/manual-testing.md) covers what lives in the native shell (downloads), and [the MCP bridge](qa/mcp-bridge.md) covers what an agent's call goes through, how to try it by hand, and its known weak spots.

CI is billed on this repo and macOS runners cost ten times the rest, so `qa` and `check` pass
locally before a push rather than being iterated against in Actions.

## Versions are pinned

```make
ADMINER_VERSION    = 6.1.1
FRANKENPHP_VERSION = 1.12.6
```

`adminer.php`, `editor.php` (compiled from its sources), 49 plugins and 28 designs all come from
that one Adminer tag, so
they cannot drift apart. Nothing ever resolves "latest", every download is checksum-verified,
and frankenphp is checked against the build provenance GitHub publishes for it.

Adminer itself is never edited. Behaviour changes go in the plugin the app loads alongside it,
and a fix to adminer goes upstream first and is carried in `patches/` only until a release has
it — see `patches/README.md`.

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
