# Desktop JavaScript

Adminer runs here inside a native WebView, not a browser tab, and this is the small layer
of page scripts that make it feel like an app: restore the reload shortcut
(`shortcuts.js`), drop the link context menu whose items make no sense here
(`context-menu.js`), open a table's data on a double-click of its name, DataGrip-style
(`table-nav.js`), drag a column of the data list to the width you want it
(`table-columns.js`), sort it without rebuilding the page (`table-sort.js`). Each file does
one thing.

Some gaps can't be closed from the page — the mouse's back/forward buttons never reach it,
so those are wired in the native shell (`dialogs_darwin.m`) instead.

They run **in the page**, not in the native shell, so a single file covers macOS, Windows
and Linux at once instead of one accelerator per platform. `Desktop\Javascript` loads them
automatically — drop a `.js` in and it is emitted with the CSP nonce Adminer requires and
a cache-buster; nothing lists them by name.

## Shared by name, not by import

These are plain scripts, not modules, so what one file offers another it puts on
`window.adminer.page`, and the file that defines it sorts earlier by name — `refresh.js`
before `table-columns.js`. `api.js` sorts first and creates the object; every other file adds
to it, never replaces it.

`window.adminer.native` is the launcher's, not ours: `bindNative()` in `launcher/main.go` puts
each binding there. It exists only in the app, never in a plain browser or the e2e — reach it
with `?.`. A page script never adds to it.

Both are declared in `adminer.d.ts` (`AdminerPage`, `AdminerNative`), and `make qa` type-checks
every script against it (`tsconfig.json`, `oxlint --type-check`): a member used or set without a
line there fails the build. A new one is a line there first. `loose-dom.d.ts` keeps the check to
that — it switches off TypeScript's opinion of what a `querySelector` returns. In Web Inspector,
`window.adminer` shows what is actually there.

What `window.adminer.page` holds so far:

- `api` — the app's own endpoints, one place instead of URLs in string literals.
- `lang(id, fallback)` — a string of ours, translated. Add it to the plugin
  catalogue (`app/src/I18n/plugin/`) as `js.<id>` and it is on every page; nothing else lists
  it. It goes through adminer's `lang()`, so sprintf: a placeholder is `{n}`, never `%`.
- `busy(on = true)` and `go(href)` — the activity indicator over the
  page; which wait takes which is in [../busy.md](../busy.md).
- `openDialog(id)` — open a modal built on `modal.latte` from a script, the way a
  `data-dialog` button would (`shortcuts.js` opens settings on the shortcuts tab on `?`).
- `openSettings()` — open settings unless another dialog is up: `shortcuts.js`, the mac menu.
- `openShortcuts()` — the same, on the shortcuts tab: `?`, the mac Help menu.
- `toast(message)` — a brief confirmation that needs no dismissing (`copy-field.js`).
- `search()` — open the table search; `reopenSettings()` — bring the
  settings dialog back after a navigation it caused (`language.js`).
- `dragWidth(edge, {grab, set, done})` — drag an edge to a width.
- `demo` — the `make demo` connection, set by PHP before any script runs.
- `refresh(href?)` — put a select page's rows on screen without reloading the
  document, adminer's highlighting re-applied and the url corrected. With a url it asks for
  that one (a sort link); without, for whatever the options form currently says, so changing a
  field first is how you apply it. Anything that goes wrong falls back to what the browser
  would have done unaided, and the answer says which happened: `null` when the indicator's
  Stop dropped it (a `desktop-stop` event from busy.js).

Put here only what closes a gap between the WebView and a real browser — app behaviour.
Database features belong in an Adminer plugin, and styling in `app/styles/`.
