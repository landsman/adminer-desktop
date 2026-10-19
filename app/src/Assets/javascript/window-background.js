/**
 * A dark page is not edged in white while the window is resized.
 *
 * A fast resize uncovers a strip WebKit has not painted yet, and on macOS that strip is the
 * window's own background. The launcher binds adSetBackground to paint it in the page's colour;
 * in a plain browser (the e2e) it is not there. Auto follows the system without a reload, so
 * that change is reported too; a Light/Dark override reloads the page.
 */

function reportBackground() {
	window.adSetBackground?.(getComputedStyle(document.body).backgroundColor);
}

reportBackground();
matchMedia("(prefers-color-scheme: dark)").addEventListener(
	"change",
	reportBackground,
);
