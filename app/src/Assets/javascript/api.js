/**
 * The app's own API actions the page talks to, gathered in one place — so what is in use is
 * visible at a glance instead of scattered as string literals across the scripts. Frozen
 * because it is a lookup table, not state. The other side is app/api.php, which routes each
 * action to a handler in src/Api/.
 *
 * These scripts are deferred and run in document order, which is this folder sorted by name
 * (Desktop\Javascript globs it). "api.js" sorts before its callers, so it is the one that creates
 * window.adminerDesktop, where every page script puts what it offers the others. `??=` because
 * `make demo` sets .demo from an inline script in the head, which runs before any of these.
 */

window.adminerDesktop ??= {};
window.adminerDesktop.api = Object.freeze({
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
window.adminerDesktop.lang = (
	(strings) => (id, fallback) =>
		strings[id] ?? fallback
)(JSON.parse(document.getElementById("ad-i18n")?.textContent || "{}"));
