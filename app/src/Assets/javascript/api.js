/**
 * window.adminer: one name for everything the page and the app share, in two halves.
 *
 * - page: what our page scripts (and, for demo, PHP) offer each other. Plain scripts, not
 *   modules, so what one file gives another goes here, and the giver sorts earlier by name.
 * - native: what the launcher binds (bindNative in launcher/main.go). Only in the app, never in
 *   a plain browser or the e2e, so it is always reached with ?.
 *
 * The typedefs below are the list of both. A member nobody declared is flagged by the IDE, so a
 * new one is a line here first — and the README's list says what it is for.
 *
 * @typedef {object} AdminerPage
 * @property {{resize: string, sidebar: string}} api Our api.php actions (below).
 * @property {(id: string, fallback: string) => string} lang A string of ours, translated (below).
 * @property {(on?: boolean) => void} busy Show or hide the activity indicator (busy.js).
 * @property {(href: string) => void} go Navigate, with the indicator up (busy.js).
 * @property {(href?: string) => Promise<boolean>} refresh A select page's rows, in place (refresh.js).
 * @property {(id: string) => void} openDialog Open a modal.latte dialog by id (modal.js).
 * @property {(message: string) => void} toast A brief confirmation (toast.js).
 * @property {() => void} [search] Open the table search (search.js).
 * @property {() => void} [reopenSettings] Keep settings open across the next load (settings-dialog.js).
 * @property {(edge: HTMLElement, how: {grab: () => number, set: (width: number) => void, done: (width: number) => void}) => void} dragWidth
 *   Drag an edge to a width (drag-width.js).
 * @property {string} [demo] The `make demo` connection, set by AdminerDesktop::head().
 *
 * @typedef {object} AdminerNative
 * @property {() => void} loaderShown The loader has parsed: show the window (loading.html).
 * @property {(css: string) => void} setBackground The page's colour, for a resize's unpainted strip.
 * @property {(mode: "light" | "dark" | "auto") => void} setAppearance What the sidebar's material is drawn in.
 * @property {() => void} [dragWindow] Move the window from a press on the toolbar band (macOS, frameless).
 * @property {() => void} [titlebarDoubleClick] Zoom or minimise, as the system setting says (macOS, frameless).
 *
 * @type {{page: AdminerPage, native?: AdminerNative}}
 */
window.adminer ??= /** @type {any} */ ({});

/**
 * The app's own API actions the page talks to, gathered in one place — so what is in use is
 * visible at a glance instead of scattered as string literals across the scripts. Frozen
 * because it is a lookup table, not state. The other side is app/api.php, which routes each
 * action to a handler in src/Api/.
 *
 * "api.js" sorts before the other scripts, so it creates page; `??=` because `make demo` sets
 * page.demo from an inline script in the head, which runs before any of these.
 */
window.adminer.page ??= /** @type {AdminerPage} */ ({});
window.adminer.page.api = Object.freeze({
	// Persists a width the user dragged — which one is the posted "what" (sidebar,
	// edit_field); read back by head() on the next load.
	resize: "api.php?action=resize",
	// Persists whether the sidebar is hidden (posted "hidden"); read back by bodyClass().
	sidebar: "api.php?action=sidebar",
});

/**
 * A string of ours in the page's language: the catalogue ID without its "js." prefix
 * (app/src/I18n/plugin/), which AdminerDesktop::head() hands over in #ad-i18n. The fallback is
 * for a page that has no block, which only a broken head() would leave.
 */
// Closed over, not a top-level const: classic scripts share one global scope, so a "strings"
// declared here would break any later file that declares its own.
window.adminer.page.lang = (
	(strings) => (id, fallback) =>
		strings[id] ?? fallback
)(JSON.parse(document.getElementById("ad-i18n")?.textContent || "{}"));
