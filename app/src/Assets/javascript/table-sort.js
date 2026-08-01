/**
 * Sort the data list without rebuilding the page.
 *
 * Clicking a column heading is a link, so adminer answers it with a whole new document: the
 * sidebar, the toolbar and the query box are all thrown away and printed again to put the same
 * rows in a different order. It flashes, and it loses where you were.
 *
 * Only the rows differ. Adminer draws no marker for which column is sorted — the headings are
 * byte for byte the same before and after — so refresh.js can swap the tbody and be done, and
 * the columns keep the widths they were dragged to because the header row is never touched.
 *
 * Sort links only. The search link beside them goes to an anchor on this page and carries
 * adminer's own handler, and anything not handled here still navigates the way it always did.
 */

const head = document.querySelector("#table thead");

if (head) {
	head.addEventListener("click", (event) => {
		const link = event.target.closest("a");
		// order[0]= is what a sort asks for, url-encoded in the href adminer prints.
		if (!link?.search.includes("order")) {
			return;
		}
		event.preventDefault();
		window.desktopRefresh(link.href);
	});
}
