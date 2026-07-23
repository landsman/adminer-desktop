/**
 * Suppress the WebView's right-click menu on links.
 *
 * On a link the native menu is all browser chrome -- Open in New Window, Download Linked
 * File, Share -- none of which means anything in a desktop app. Cancel it on links only,
 * so right-clicking a cell value to copy it still works.
 *
 * @param {MouseEvent} e
 */
function suppressLinkMenu(e) {
	if (e.target.closest("a")) {
		e.preventDefault();
	}
}

addEventListener("contextmenu", suppressLinkMenu);
