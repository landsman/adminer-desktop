/**
 * Re-run the page's own query and swap the rows in, without reloading the document.
 *
 * Adminer rebuilds the whole page on every click, which is fine when you asked to go
 * somewhere and a flash when you did not — a column dragged wider needs longer values, and
 * that is a round trip nobody asked for a new page from.
 *
 * `window.desktopRefresh()` is that round trip: it asks for what the options form currently
 * says, puts the answer's rows in place of the ones on screen, and leaves everything else
 * alone. Change a field first and this applies it — which is what pressing Select does, minus
 * the document swap.
 *
 * What makes it safe to swap only the rows is where adminer binds things: tableClick is on
 * the table, not on the rows, so selecting and inline editing survive. What does not survive
 * is the highlighting, which runs once at load — so it is re-applied here, through adminer's
 * own adminerHighlighter hook, the way its selectLoadMore() does when it appends a page.
 *
 * Anything that goes wrong falls back to submitting the form, which is adminer's own way and
 * always works. The answer says which happened: true if the rows were swapped in place.
 */

window.desktopRefresh = async (href) => {
	// The results table, and the form the query's options live in — the select page has both,
	// and a page without the rows has nothing to refresh.
	const table = document.querySelector("#table");
	const form = document.querySelector("#form");
	const body = table?.tBodies[0];
	if (!body || (!form && !href)) {
		return false;
	}

	// A link's own url when given one — sorting is a link, and it asks for the same page in a
	// different order. Otherwise the request a submit would make: the form's own fields, as
	// adminer's GET expects them.
	const url =
		href ?? `${location.pathname}?${new URLSearchParams(new FormData(form))}`;
	try {
		const answer = await fetch(url);
		const page = new DOMParser().parseFromString(
			await answer.text(),
			"text/html",
		);
		const fresh = page.querySelector("#table tbody");
		if (!fresh) {
			throw new Error("no rows in the answer");
		}
		// Before it is in the document, so the values are never briefly plain.
		if (typeof adminerHighlighter === "function") {
			adminerHighlighter(fresh.querySelectorAll("code"));
		}
		body.replaceWith(fresh);
		// So a reload asks for what is on screen rather than what was asked for first.
		history.replaceState(null, "", url);
		return true;
	} catch {
		// Whatever went wrong, do what the browser would have done unaided: follow the link, or
		// press Select. Both work, and neither leaves the page showing something that is no
		// longer true.
		if (href) {
			location.assign(href);
		} else {
			form.requestSubmit();
		}
		return false;
	}
};
