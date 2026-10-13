/**
 * Keyboard shortcuts for Adminer Desktop.
 *
 * These run in the page, not the native shell, so one file covers macOS, Windows and
 * Linux identically -- the WebViews wire none of these up themselves, so without this the
 * page could not be driven from the keyboard on any platform.
 */

/**
 * Reload the page on the shortcut every browser uses: F5, and Cmd/Ctrl+R.
 * metaKey is macOS's Cmd, ctrlKey is Windows and Linux.
 *
 * @param {KeyboardEvent} e
 */
function reloadShortcut(e) {
	if (
		e.key === "F5" ||
		((e.metaKey || e.ctrlKey) && (e.key === "r" || e.key === "R"))
	) {
		e.preventDefault();
		location.reload();
	}
}

addEventListener("keydown", reloadShortcut);

/**
 * Open the list of shortcuts on ?, the key GitHub, Gmail and most web apps use for it: the
 * settings dialog, on its shortcuts tab.
 *
 * Not while typing — ? is a character there — and not over another dialog. The list itself is
 * src/Shortcuts/, printed for the platform the app runs on; a shortcut added here gets a row
 * there.
 *
 * @param {KeyboardEvent} e
 */
function helpShortcut(e) {
	if (
		e.key !== "?" ||
		e.metaKey ||
		e.ctrlKey ||
		e.altKey ||
		e.target.closest?.("input, textarea, select, [contenteditable]") ||
		document.querySelector("dialog[open]")
	) {
		return;
	}
	const tab = document.querySelector("#desktop-tab-shortcuts");
	if (!tab) {
		return;
	}
	e.preventDefault();
	// Checked before opening: the tabs are radios, so this is the whole of switching to it.
	tab.checked = true;
	window.desktopOpenDialog?.("desktop-settings");
}

addEventListener("keydown", helpShortcut);
