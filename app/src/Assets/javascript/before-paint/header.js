/**
 * The content panel's header row: the narrow window's burger and the path, which shrinks instead
 * of running under it. The path starts at a home mark rather than the driver's name and the
 * server's address, which is the connection's badge's to show, in the sidebar's logo row.
 *
 * Nothing here may be seen to move, so it runs as the page is parsed rather than after: loaded
 * from <head> without defer (Javascript::beforePaint()), it watches the parser, and a mutation
 * observer's callback runs before the browser gets to paint what was just added. The
 * breadcrumb is rewritten as soon as it is complete, the badge placed the moment it arrives —
 * at the very end of the page, in the sidebar. Deferred, the header was painted as adminer drew
 * it and then rearranged, on every page and in the snapshot a view transition takes of it.
 *
 * The badge takes Logout's place: it says who and where, and leads to the start screen, whose
 * cards log out (layout.css hides the form).
 *
 * Only under the adminer-desktop theme; any other design is left as adminer drew it.
 */

{
	let pathDone = false;
	let badgeDone = false;
	let linksDone = false;

	// Our design, on the side of the scheme in view: adminer links it per side with a media
	// query when the other side is a gallery design (Theme::cssMap()). Asked of the <link>, not
	// a computed style, as the sheet may still be loading — the page will not paint before it.
	const ours = () =>
		[
			...document.querySelectorAll(
				'link[rel="stylesheet"][href*="/designs/adminer-desktop/"]',
			),
		].some((link) => !link.media || matchMedia(link.media).matches);

	const rewritePath = (breadcrumb) => {
		// The current page's name is a bare text node, which no selector reaches; in a span it
		// can take an ellipsis too, once the links have given all they can.
		const current = breadcrumb.lastChild;
		if (current?.nodeType === Node.TEXT_NODE && current.textContent.trim()) {
			const span = document.createElement("span");
			span.className = "ad-breadcrumb-current";
			span.textContent = current.textContent.replace(/^\s*»?\s*/, "").trim();
			current.replaceWith(" » ", span);
		}
		// The driver's name ("PostgreSQL") is where the path starts, not something to read on
		// every page: a home mark, with the name kept as its label and tooltip.
		const home = breadcrumb.querySelector(":scope > a");
		if (home) {
			home.title = home.textContent;
			home.setAttribute("aria-label", home.textContent);
			home.className = "ad-breadcrumb-home";
			home.textContent = "";
			// The server's address is the badge's to show; the home mark takes its link instead,
			// to the database list. The logo and the badge lead further out, to the start screen.
			const server = home.nextElementSibling;
			if (server) {
				if (server.href) {
					home.href = server.href;
				}
				server.previousSibling.remove();
				server.remove();
			}
		}
		// Adminer's burger for a narrow window, which it floats over the corner the breadcrumb
		// occupies here; at the start of the row it is beside the path instead of on top of it.
		// It comes before #content, so it is parsed by now.
		const burger = document.querySelector("#menuopen");
		if (burger) {
			breadcrumb.prepend(burger);
		}
	};

	// In the sidebar's logo row, in the logo's place (layout.css hides it): the sidebar is the
	// connection's, so it says whose. The narrow window's drawer is the same sidebar.
	const placeBadge = (badge) => {
		document.querySelector("#menu h1")?.prepend(badge);
		badge.classList.add("ad-placed");
		// Up to the panel, whose border takes the colour along the badge's row (layout.css).
		const conn = badge.style.getPropertyValue("--ad-conn");
		if (conn) {
			document.querySelector("#foot")?.style.setProperty("--ad-conn", conn);
		}
	};

	// No title bar (macOS, launcher's installFrameless): the path's row is the window's toolbar
	// (window.css), and as in Mail it carries the page's own actions and the search. The search is
	// a button that opens the one Shift Shift opens (search.js); its label comes from the i18n block
	// head() printed, as api.js, which would read it, is deferred.
	const frameless = document.documentElement.classList.contains("ad-frameless");
	let toolbar = null;
	let actionsDone = false;

	// A gallery design knows nothing of the missing title bar: the window's own layout for it is
	// ours alone (window.css). Said once the parser reaches <body> — adminer links the design after
	// this script, so asking at the top found none — and before anything paints, so
	// css/frameless.css can at least keep its page out from under the lights, and the scripts that
	// dress the window stand down.
	let foreignChecked = false;
	const markForeign = () => {
		foreignChecked = true;
		if (frameless && !ours()) {
			document.documentElement.classList.add("ad-frameless-foreign");
		}
	};

	// Which action each of the page's links is, for its icon (window.css): adminer's URLs end in
	// the action's parameter (`&scheme=`) or point at a section of the page (`#routines`). Marked
	// as they arrive, as the parser fills the group after it has moved; "" for one with no icon,
	// which keeps its word.
	const TOOL_PARAMS = {
		scheme: "alter",
		database: "alter",
		create: "alter",
		schema: "diagram",
		privileges: "privileges",
		select: "data",
		table: "structure",
		edit: "new",
	};
	const TOOL_SECTIONS = {
		"#routines": "routine",
		"#sequences": "sequence",
		"#user-types": "types",
		"#events": "events",
	};
	const markTools = () => {
		for (const link of toolbar.querySelectorAll("a:not([data-tool])")) {
			const url = new URL(link.href, location.href);
			const param = Object.keys(TOOL_PARAMS).find((key) =>
				url.searchParams.has(key),
			);
			link.dataset.tool = TOOL_SECTIONS[url.hash] ?? TOOL_PARAMS[param] ?? "";
		}
	};

	const buildToolbar = (breadcrumb) => {
		toolbar = document.createElement("div");
		toolbar.className = "ad-toolbar";
		const strings = JSON.parse(
			document.getElementById("ad-i18n")?.textContent || "{}",
		);
		const search = document.createElement("button");
		search.type = "button";
		search.className = "ad-toolbar-search";
		search.textContent = strings["toolbar.search"] ?? "Search";
		search.addEventListener("click", () => window.desktopSearch?.());
		toolbar.append(search);
		breadcrumb.append(toolbar);
	};

	// The page's actions are the first links adminer prints under the title, before any content:
	// only its status line, a message and the scripts that go with them may come between. A list of
	// links further down the page is something else and stays.
	const pageActions = () => {
		const links = document.querySelector("#content > p.links");
		let before = links?.previousElementSibling;
		while (before?.matches("#ajaxstatus, .message, .error, script")) {
			before = before.previousElementSibling;
		}
		return before?.matches("h2, #breadcrumb") ? links : null;
	};

	const observer = new MutationObserver(() => {
		if (!foreignChecked && document.body) {
			markForeign();
		}
		const breadcrumb = document.querySelector("#content #breadcrumb");
		// Complete once the parser has moved past it: something follows it.
		if (!pathDone && breadcrumb?.nextSibling) {
			if (!ours()) {
				observer.disconnect();
				return;
			}
			rewritePath(breadcrumb);
			pathDone = true;
			// Only where the path names a page: on the server's own page the row is hidden
			// (layout.css), and its links are that page's content.
			if (frameless && breadcrumb.querySelector(".ad-breadcrumb-current")) {
				buildToolbar(breadcrumb);
			}
		}
		// Moved the moment they arrive, like the sidebar's actions below.
		const actions = toolbar && !actionsDone && pageActions();
		if (actions) {
			actions.classList.add("ad-toolbar-actions");
			toolbar.prepend(actions);
			actionsDone = true;
		}
		if (actionsDone) {
			markTools();
		}
		// The sidebar's actions on the pickers' row rather than one of their own below it: the
		// pickers are short now (sidebar.css), and two rows of controls read as one toolbar.
		// They follow the pickers' form, and move the moment they arrive: the parser goes on filling
		// the element it holds open wherever it now is. Waiting for something to follow them did
		// not do, as on the server's page nothing does.
		const links = document.querySelector("#menu > .links");
		const dbs = document.querySelector("#dbs");
		if (pathDone && links && dbs) {
			dbs.append(links);
			linksDone = true;
		}
		// The badge comes before the pickers, so placing it is not the end of the watch.
		const badge = document.querySelector(
			"#ad-connection-badge:not(.ad-placed)",
		);
		if (pathDone && badge) {
			placeBadge(badge);
			badgeDone = true;
		}
		if (badgeDone && linksDone) {
			observer.disconnect();
		}
	});
	observer.observe(document, { childList: true, subtree: true });
	// A page without a badge (the login) or without actions has nothing to wait for past its end.
	document.addEventListener("DOMContentLoaded", () => {
		observer.disconnect();
		if (actionsDone) {
			markTools();
		}
	});
}
