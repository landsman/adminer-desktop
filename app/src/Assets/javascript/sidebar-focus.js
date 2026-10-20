/**
 * Whether the sidebar has the focus, for the open table's highlight (sidebar.css): the accent
 * while it does, grey once a click lands in the content or the window goes to the background —
 * a native list's selection, as in Mail.
 *
 * A click on a table loads a new page, which starts with the focus nowhere, so where the last
 * press landed is carried across in sessionStorage: this window's, gone with it.
 */

const KEY = "ad-sidebar-focused";
const root = document.documentElement;

/** @param {boolean} on */
function mark(on) {
	root.classList.toggle(KEY, on && document.hasFocus());
}

let focused = false;
try {
	focused = sessionStorage.getItem(KEY) === "1";
} catch {
	// Storage refused: the highlight just starts grey.
}
mark(focused);

addEventListener("pointerdown", (event) => {
	focused = Boolean(event.target.closest?.("#foot"));
	try {
		sessionStorage.setItem(KEY, focused ? "1" : "0");
	} catch {
		// As above.
	}
	mark(focused);
});
addEventListener("blur", () => mark(false));
addEventListener("focus", () => mark(focused));
