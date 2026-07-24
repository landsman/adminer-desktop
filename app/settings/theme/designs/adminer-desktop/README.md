Adminer Desktop — the app's own default theme, not a downloaded design.

Unlike the gallery designs beside it (single upstream `adminer.css` / `adminer-dark.css`
files, pinned and never edited), this one is ours to change. It is split into components
pulled in by native `@import` from `adminer.css`:

- `tokens.css`  — the variable system (Adminer's `--bg/--fg/--dim/--lit` + our `--ad-*`) as `light-dark()` pairs, density levels, font stacks
- `base.css`    — type, links, headings, focus, messages, the dark icon invert
- `tables.css`  — the data grid
- `forms.css`   — inputs and buttons
- `sidebar.css` — the menu and breadcrumb

One file carrying both schemes: every scheme difference is a `light-dark(light, dark)`
token, and the used `color-scheme` picks the side. Adminer sets that from its
`<meta name="color-scheme">`, which `Theme::cssMap()` drives from the appearance
preference — `light dark` for Auto (follows the OS), or pinned to one side for a Light /
Dark override. The lone colour-only exception, inverting Adminer's sprite icons on the
dark surface, keys off the `theme-auto` / `theme-dark` body class instead (`base.css`).
It is excluded from the gallery (`Theme::designs()`) because it is the default, offered as
the empty "Adminer Desktop" option on each side rather than one entry among the rest.

Per-OS adaptation is mostly `system-ui` (the OS UI font, no branching); the `os-mac` /
`os-windows` / `os-linux` body class from `AdminerDesktop::bodyClass()` is the hook for
the rest. Row density (`density-compact` / `-cozy` / `-comfortable`) comes from the same
place.
