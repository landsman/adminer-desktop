/**
 * Whether the sidebar has the focus, for the open table's highlight (sidebar.css): the accent
 * while it does, grey once a click or the keyboard moves into the content or the window goes to
 * the background — a native list's selection, as in Mail.
 *
 * A click on a table loads a new page, which starts with the focus nowhere, so where the last
 * press landed is carried across in sessionStorage: this window's, gone with it.
 */
// In a block: classic scripts share one global scope, and a top-level `const KEY` here made
// sidebar-scroll.js, which declares its own, fail to load on every page.
{
	const KEY = "ad-sidebar-focused";
	const root = document.documentElement;
	let focused = false;

	const mark = (on) => root.classList.toggle(KEY, on && document.hasFocus());

	const remember = (on) => {
		focused = on;
		try {
			sessionStorage.setItem(KEY, on ? "1" : "0");
		} catch {
			// Storage refused: the highlight just starts grey on the next page.
		}
		mark(on);
	};

	try {
		focused = sessionStorage.getItem(KEY) === "1";
	} catch {
		// As above.
	}
	mark(focused);

	addEventListener("pointerdown", (event) => {
		remember(Boolean(event.target.closest?.("#foot")));
	});
	// The keyboard moves the focus too: Tab into the sidebar marks it, out of it unmarks it.
	addEventListener("focusin", (event) => {
		remember(Boolean(event.target.closest?.("#foot")));
	});
	addEventListener("blur", () => mark(false));
	addEventListener("focus", () => mark(focused));
}
