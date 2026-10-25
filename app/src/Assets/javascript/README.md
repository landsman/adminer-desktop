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
`window.adminerDesktop`, and the file that defines it sorts earlier by name — `refresh.js`
before `table-columns.js`. `api.js` sorts first and creates the object; every other file adds
to it, never replaces it.

`window.ad*` is the native bridge's, not ours: the launcher's `w.Bind()` registers a function
on `window` itself and cannot reach into an object, so `adDragWindow`, `adTitlebarDoubleClick`,
`adLoaderShown`, `adSetBackground` and `adSetAppearance` stay there. They exist only in the
app, never in a plain browser — call them with `?.`. A page script never defines an `ad*`.

What `window.adminerDesktop` holds so far:

- `api` — the app's own endpoints, one place instead of URLs in string literals.
- `lang(id, fallback)` — a string of ours, translated. Add it to the plugin
  catalogue (`app/src/I18n/plugin/`) as `js.<id>` and it is on every page; nothing else lists
  it. It goes through adminer's `lang()`, so sprintf: a placeholder is `{n}`, never `%`.
- `busy(on = true)` and `go(href)` — the activity indicator over the
  page; which wait takes which is in [../busy.md](../busy.md).
- `openDialog(id)` — open a modal built on `modal.latte` from a script, the way a
  `data-dialog` button would (`shortcuts.js` opens settings on the shortcuts tab on `?`).
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
