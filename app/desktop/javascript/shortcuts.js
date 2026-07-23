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
	if (e.key === 'F5' || ((e.metaKey || e.ctrlKey) && (e.key === 'r' || e.key === 'R'))) {
		e.preventDefault();
		location.reload();
	}
}

addEventListener('keydown', reloadShortcut);
