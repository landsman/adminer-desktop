/**
 * Keep the sidebar's scroll position across Adminer's full-page navigations.
 *
 * The table list scrolls within its own panel (the islands layout). Every click reloads the
 * whole page, which resets that scroll to the top — so opening a table far down the list jumps
 * it back up, and the cross-fade between pages shows the sidebar lurching. This saves the
 * position as the page unloads and restores it on the next load, so the sidebar stays put
 * while only the content changes.
 */

const menu = document.querySelector("#menu");
const KEY = "desktop-sidebar-scroll";

if (menu) {
	const saved = sessionStorage.getItem(KEY);
	if (saved) {
		menu.scrollTop = +saved;
	}
	// pagehide fires before the page is torn down, after Adminer has laid the sidebar out — so
	// scrollTop is the real position the user left it at.
	addEventListener("pagehide", () => {
		sessionStorage.setItem(KEY, String(menu.scrollTop));
	});
}
