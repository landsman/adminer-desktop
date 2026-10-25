---
name: page-scripts
description: Write, change or debug a page script under app/src/Assets/javascript/ — the plain deferred scripts that make adminer behave like an app inside the WebView. Use when adding a .js there or in before-paint/, sharing a helper between scripts (window.adminer.page), calling or adding a launcher binding (window.adminer.native, bindNative), declaring either in adminer.d.ts, a TS error from oxlint --type-check, showing a translated string from JavaScript, posting to app/api.php, waiting on the busy indicator, or when a script binds to the wrong element, never runs, or works in a browser but not in the app.
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
4. **What a script offers others goes on `window.adminer.page`.** `api.js` sorts first and
   creates it; add a member (`window.adminer.page.toast = …`), never reassign the object.
5. **What the launcher offers is `window.adminer.native`**, put there by `bindNative()` in
   `launcher/main.go` — never a bare `w.Bind()`, which can only write `window[name]`. It exists
   only in the app, never in a browser or the e2e, so reach it with `?.`
   (`window.adminer.native?.setBackground(…)`). A page script never adds to it.
6. **Declare it in `adminer.d.ts` first.** Every member of either half is a line on the
   `AdminerPage` or `AdminerNative` interface there, with its signature, a one-line doc comment
   and the file that defines it. `make qa` type-checks every script against it (`tsconfig.json`,
   `oxlint --type-aware --type-check`), so a typo, a wrong argument or a member set without its
   line fails the build. Mark one that is not always set optional (`search?(): void`), add it to
   the README's list, and give the function a JSDoc block. The DOM is deliberately loose
   (`loose-dom.d.ts`) and `strictNullChecks` is off, so a missing `?.` on `native` is not
   caught — that one is on you. A promise you do not wait for is `void`ed.
7. **Text through `window.adminer.page.lang(id, fallback)`.** Add `js.<id>` to the plugin
   catalogue in `app/src/I18n/plugin/`, every language. It passes through sprintf, so a
   placeholder is `{n}`, never `%d`.
8. **URLs of our api from `window.adminer.page.api`**, never a string literal; a new action
   is a key there plus a class in `src/Api/`.
9. **A wait shows the busy indicator** — `go(href)` instead of setting `location`, `busy()`
   around a fetch. `app/src/Assets/busy.md` says which wait takes which.
10. **`before-paint/` only for what would visibly move.** It blocks parsing and runs before
    `api.js`, so `window.adminer.page` exists there only inside a handler.
11. **Prove it in the app, not with curl.** `make debug` opens Safari's Web Inspector on the
    page, where `window.adminer` shows what is really defined; `mise run format`, then
    `make qa` runs oxfmt and oxlint; `make e2e` for behaviour. In an e2e step, a JavaScript
    string gets `// language=JavaScript` on the line before it, and the step stubs
    `window.adminer.native` itself where a scenario needs the app's chrome.
