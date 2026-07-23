Adminer Desktop — the app's own default theme, not a downloaded design.

Unlike the gallery designs beside it (single upstream `adminer.css` / `adminer-dark.css`
files, pinned and never edited), this one is ours to change. It is split into components
pulled in by native `@import` from `adminer.css`:

- `tokens.css`  — the variable system (Adminer's `--bg/--fg/--dim/--lit` + our `--ad-*`), density levels, font stacks
- `base.css`    — type, links, headings, focus, messages
- `tables.css`  — the data grid
- `forms.css`   — inputs and buttons
- `sidebar.css` — the menu and breadcrumb
- `dark.css`    — the `@media (prefers-color-scheme: dark)` token overrides, imported last

One file carrying both schemes: `Theme::cssMap()` hands it to Adminer with no media query,
so its internal `@media` does the light/dark switching. It is excluded from the gallery
(`Theme::designs()`) because it is the default, offered as the empty "Adminer Desktop"
option on each side rather than one entry among the rest.

Per-OS adaptation is mostly `system-ui` (the OS UI font, no branching); the `os-mac` /
`os-windows` / `os-linux` body class from `AdminerDesktop::bodyClass()` is the hook for
the rest. Row density (`density-compact` / `-cozy` / `-comfortable`) comes from the same
place.
