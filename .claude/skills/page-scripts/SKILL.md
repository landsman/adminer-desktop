---
name: page-scripts
description: Write, change or debug a page script under app/src/Assets/javascript/ — the plain deferred scripts that make adminer behave like an app inside the WebView. Use when adding a .js there or in before-paint/, sharing a helper between scripts (window.adminerDesktop), calling the native bridge (window.ad*), showing a translated string from JavaScript, posting to app/api.php, waiting on the busy indicator, or when a script binds to the wrong element, never runs, or works in a browser but not in the app.
---

# Page scripts

`app/src/Assets/javascript/README.md` lists what is already shared and what belongs here at
all; read it before adding a helper that may exist. The rules:

1. **One file, one thing.** Drop a `.js` into the folder and `Desktop\Javascript` loads it,
   deferred, with the CSP nonce — nothing lists it. No inline `<script>` in a template, no
   JavaScript in a PHP string: adminer's `qsl()` finds the last match in the whole document,
   so an inline script binds to the wrong element. Find yours by id.
2. **Plain scripts, one global scope.** No `import`/`export`. A top-level `const` collides
   with the same name in any other file, so wrap a file in `(() => { … })()` or give its
   top-level names something only it would use.
3. **Order is the file name.** Deferred scripts run sorted by name, so a helper's file must
   sort before its callers (`refresh.js` before `table-columns.js`). Calling one later, from an
   event handler, needs no ordering.
4. **What a script offers others goes on `window.adminerDesktop`** — `api.js` sorts first and
   creates it; add a member (`window.adminerDesktop.toast = …`), never reassign the object,
   and add a line for it to the README.
5. **`window.ad*` is the launcher's alone.** `w.Bind()` in `launcher/` can only register on
   `window`. Those functions exist only in the app, never in a browser or the e2e, so call
   them with `?.` (`window.adSetBackground?.(…)`). A page script never defines an `ad*`.
6. **Text through `window.adminerDesktop.lang(id, fallback)`.** Add `js.<id>` to the plugin
   catalogue in `app/src/I18n/plugin/`, every language. It passes through sprintf, so a
   placeholder is `{n}`, never `%d`.
7. **URLs of our api from `window.adminerDesktop.api`**, never a string literal; a new action
   is a key there plus a class in `src/Api/`.
8. **A wait shows the busy indicator** — `go(href)` instead of setting `location`, `busy()`
   around a fetch. `app/src/Assets/busy.md` says which wait takes which.
9. **`before-paint/` only for what would visibly move.** It blocks parsing and runs before
   `api.js`, so `window.adminerDesktop` exists there only inside a handler.
10. **Prove it in the app, not with curl.** `make debug` opens Safari's Web Inspector on the
    page; `mise run format`, then `make qa` runs oxfmt and oxlint; `make e2e` for behaviour.
    In an e2e step, a JavaScript string gets `// language=JavaScript` on the line before it.
