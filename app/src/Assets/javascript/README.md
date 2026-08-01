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

These are plain scripts, not modules, so what one file offers another it offers on `window`,
and the file that defines it sorts earlier by name — `api.js` before its callers, `refresh.js`
before `table-columns.js`. Two so far:

- `window.desktopApi` — the app's own endpoints, one place instead of URLs in string literals.
- `window.desktopRefresh(href?)` — put a select page's rows on screen without reloading the
  document, adminer's highlighting re-applied and the url corrected. With a url it asks for
  that one (a sort link); without, for whatever the options form currently says, so changing a
  field first is how you apply it. Anything that goes wrong falls back to what the browser
  would have done unaided, and the answer says which happened.

Put here only what closes a gap between the WebView and a real browser — app behaviour.
Database features belong in an Adminer plugin, and styling in `app/styles/`.
