# Adminer Desktop theme

The app's own default look. The gallery designs beside it are downloaded from upstream, pinned and never edited; this one is ours to change.

- It is the empty "Adminer Desktop" option on each side of the settings, so `Theme::designs()` keeps it out of the gallery.
- It reskins Adminer through Adminer's `--bg`, `--fg`, `--dim` and `--lit`, plus our own `--ad-*`.

## Files

`adminer.css` is the entry point. It pulls these in with `@import`, for every OS:

| File | What it holds |
|------|---------------|
| `tokens.css` | the variables as `light-dark()` pairs, density levels, scaling, font stacks |
| `layout.css` | the two panels, the sidebar's drag handle, the breadcrumb as a sticky header |
| `base.css` | type, links, headings, messages, the dark icon invert, the page transition |
| `tables.css` | the data grid |
| `forms.css` | inputs and buttons |
| `sidebar.css` | the menu and the table list |
| `settings.css` | the settings dialog |
| `window.css` | the frameless macOS window, laid out as Mail is, under `.ad-frameless` |

`Theme::cssMap()` adds one more right after `adminer.css`, by OS, and never over a gallery design:

| File | Loaded on | What it holds |
|------|-----------|---------------|
| `mac-os.css` | macOS | the system accent, neutral greys, corners, overlay scrollbars |
| `windows-os.css` | Windows | Windows 11's tokens and controls, with values from WinUI's resources |

Linux has no file of its own.

## Light and dark

- Every scheme difference is a `light-dark(light, dark)` token, and the used `color-scheme` picks the side.
- Adminer sets that from `<meta name="color-scheme">`, which `Theme::cssMap()` drives from the appearance preference: `light dark` for Auto, one side for a Light or Dark override.
- So no scheme media query, and no `color-scheme` in the CSS: a value there would beat the meta and defeat the override.
- The one exception is inverting Adminer's sprite icons, which is not a colour. It keys off the `theme-auto` and `theme-dark` body classes (`base.css`).

## Hooks on the body

All of them come from `AdminerDesktop::bodyClass()`:

- `mac-os`, `windows-os`, `linux-os`: the OS. `make debug` can simulate another one.
- `density-compact`, `density-cozy`, `density-comfortable`: row density.
- `scale-100` to `scale-200`: the UI scale.
- `theme-auto`, `theme-light`, `theme-dark`: the appearance preference.

A token an OS file sets on the body has to restate what `html` derives from it. A `var()` resolves where it is declared, so `--ad-accent-text` on `html` keeps `html`'s accent.
