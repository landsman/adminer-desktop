/**
 * The app's own PHP endpoints the page talks to, gathered in one place — so what is in use is
 * visible at a glance instead of scattered as string literals across the scripts. Frozen
 * because it is a lookup table, not state.
 *
 * These scripts are deferred and run in document order, which is this folder sorted by name
 * (Desktop\Javascript globs it). "api.js" sorts before its callers, so window.desktopApi is
 * set by the time they run.
 */

window.desktopApi = Object.freeze({
	// Persists the dragged sidebar width; read back by head() on the next load.
	sidebarWidth: "settings/sidebar-width.php",
});
