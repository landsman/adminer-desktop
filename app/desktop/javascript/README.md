# Desktop JavaScript

Adminer runs here inside a native WebView, not a browser tab, and this is the small layer
of page scripts that make it feel like an app: restore the reload shortcut
(`shortcuts.js`), drop the link context menu whose items make no sense here
(`context-menu.js`), open a table's data on a double-click of its name, DataGrip-style
(`table-nav.js`). Each file does one thing.

Some gaps can't be closed from the page — the mouse's back/forward buttons never reach it,
so those are wired in the native shell (`dialogs_darwin.m`) instead.

They run **in the page**, not in the native shell, so a single file covers macOS, Windows
and Linux at once instead of one accelerator per platform. `Desktop\Javascript` loads them
automatically — drop a `.js` in and it is emitted with the CSP nonce Adminer requires and
a cache-buster; nothing lists them by name.

Put here only what closes a gap between the WebView and a real browser — app behaviour.
Database features belong in an Adminer plugin, and styling in `app/styles/`.
