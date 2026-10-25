/**
 * A dark page is not edged in white while the window is resized.
 *
 * A fast resize uncovers a strip WebKit has not painted yet, and on macOS that strip is the
 * window's own background. The launcher binds native.setBackground to paint it in the page's colour;
 * in a plain browser (the e2e) it is not there. Auto follows the system without a reload, so
 * that change is reported too; a Light/Dark override reloads the page.
 */

function reportBackground() {
	window.adminer.native?.setBackground(
		getComputedStyle(document.body).backgroundColor,
	);
}

reportBackground();
// The sidebar is the system's material, drawn in the window's appearance; a Light/Dark override
// has to pin the window too, or a light page keeps a dark sidebar on a dark Mac.
window.adminer.native?.setAppearance(
	document.body.classList.contains("theme-light")
		? "light"
		: document.body.classList.contains("theme-dark")
			? "dark"
			: "auto",
);
matchMedia("(prefers-color-scheme: dark)").addEventListener(
	"change",
	reportBackground,
);
