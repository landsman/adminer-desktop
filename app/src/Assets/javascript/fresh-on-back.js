/**
 * Going back shows the database as it is now, not as it was when the page was left.
 *
 * WKWebView keeps a page it leaves in its back-forward cache and restores that very document on
 * Back, so a table created or dropped since is missing from, or still in, the sidebar. A restored
 * page fires pageshow with persisted set, and reloading it asks the server again.
 *
 * Not a page that answered a POST (an SQL command's results): reloading it would send the
 * statements again, and its results are a record of that run, not a view of the database.
 */

addEventListener("pageshow", (event) => {
	if (event.persisted && !document.body.classList.contains("ad-posted")) {
		location.reload();
	}
});
