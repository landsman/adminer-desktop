# adminer-desktop

[Adminer](https://www.adminer.org) as a desktop app. No PHP install, no web server,
no browser tab.

Adminer itself is not modified or forked — this repo downloads the official released
`adminer.php` at a pinned version and wraps it. See [PLAN.md](PLAN.md).

## Status

Milestone 0 (verify the transport) — see PLAN.md for what is built and what is not.
First target is **macOS on Apple Silicon**; other platforms are one Makefile variable
away but are not built or tested yet.

## Build

```sh
make fetch    # download pinned adminer, editor, plugins, designs, frankenphp
make verify   # check downloads against checksums.txt
make check    # M0: assert the transport neither times out nor buffers (~2 min)
make serve    # run it at http://127.0.0.1:18000/adminer.php
```

There is no Caddyfile. FrankenPHP *is* Caddy, and its `php-server` defaults already do
the right thing — no request timeout, no response buffering, plaintext HTTP, and a
localhost-only bind. `make check` is what holds those defaults to account.

## Versions are pinned

Two variables in the `Makefile` decide everything:

```make
ADMINER_VERSION    = 5.5.1
FRANKENPHP_VERSION = 1.12.6
```

`adminer.php`, `editor.php`, all 51 plugins and all 26 designs come from that one
Adminer tag, so they can never drift apart. Bumping is a one-line commit plus
`make checksums`, reviewable in a diff. Nothing ever resolves "latest".

## Plugins

All 51 official plugins ship. **None are enabled by default** — several conflict with
each other and a default set is a taste decision.

```
app/plugins-available/   the catalogue: shipped, never loaded
app/adminer-plugins/     the enabled ones
```

The enabled set *is* the directory contents, so ticking a box in the UI and dragging
a downloaded plugin into the folder do the same thing.

## Designs

26 designs ship, switchable from a dropdown via Adminer's own `designs` plugin.
Bundled rather than downloaded on demand: a few hundred KB of CSS next to a 178 MB
runtime isn't worth fetch code, cache invalidation, and breaking offline use.

## Licence

Adminer is Apache-2.0 / GPL-2.0 (dual). This wrapper is MIT.
