# Desktop JavaScript

Adminer runs here inside a native WebView, not a browser tab — so the conveniences a
browser gives for free are simply absent: no reload shortcut, and other small gaps like
it. Each file in this folder restores one of them.

They run **in the page**, not in the native shell, so a single file covers macOS, Windows
and Linux at once instead of one accelerator per platform. `Desktop\Javascript` loads them
automatically — drop a `.js` in and it is emitted with the CSP nonce Adminer requires and
a cache-buster; nothing lists them by name.

Put here only what closes a gap between the WebView and a real browser — app behaviour.
Database features belong in an Adminer plugin, and styling in `app/styles/`.
