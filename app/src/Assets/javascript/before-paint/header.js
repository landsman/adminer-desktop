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

	// Our strings in the page's language, from the block head() prints: api.js, which reads it for
	// the other scripts, is deferred, and this runs as the page parses. Read once, when first asked.
	let catalogue = null;
	const strings = () => {
		catalogue ??= JSON.parse(
			document.getElementById("ad-i18n")?.textContent || "{}",
		);
		return catalogue;
	};
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
			const title = current.textContent.replace(/^\s*»?\s*/, "").trim();
			// adminer's title is the kind of page and its object ("Schema: public", "Select: users"):
			// apart, so a toolbar can make the object its title and the kind part of the path
			// (window.css). The colon stays, hidden there, so the text reads as adminer wrote it.
			const [, kind, name] = title.match(/^([^:]+):\s+(.+)$/) ?? [];
			if (name) {
				const kindSpan = document.createElement("span");
				kindSpan.className = "ad-breadcrumb-kind";
				kindSpan.textContent = `${kind}: `;
				const nameSpan = document.createElement("span");
				nameSpan.className = "ad-breadcrumb-name";
				nameSpan.textContent = name;
				span.append(kindSpan, nameSpan);
			} else {
				span.textContent = title;
			}
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
		// The path's » between its steps, in a span of their own so the theme can draw them
		// (window.css draws a thin ›): as bare text no selector reaches them. After the home mark
		// takes the server's place, which finds the server as the element after it.
		for (const node of [...breadcrumb.childNodes]) {
			if (node.nodeType === Node.TEXT_NODE && node.textContent.includes("»")) {
				const sep = document.createElement("span");
				sep.className = "ad-breadcrumb-sep";
				sep.setAttribute("aria-hidden", "true");
				sep.textContent = "»";
				node.replaceWith(sep);
			}
		}
		// Adminer's burger for a narrow window, which it floats over the corner the breadcrumb
		// occupies here; at the start of the row it is beside the path instead of on top of it.
		// It comes before #content, so it is parsed by now.
		const burger = document.querySelector("#menuopen");
		if (burger) {
			breadcrumb.prepend(burger);
		}
		// With no title bar the row is the window's toolbar, whose title is the object you are on
		// alone, as Mail's is; the way there goes to a path bar along the window's foot, as
		// Finder's does (window.css) — "⌂ PostgreSQL › demo › public › users", each step a link.
		const name = breadcrumb.querySelector(".ad-breadcrumb-current");
		if (frameless && name) {
			const bar = document.createElement("nav");
			bar.id = "ad-pathbar";
			bar.setAttribute("aria-label", strings()["pathbar.label"] ?? "Path");
			for (const node of [...breadcrumb.childNodes]) {
				if (node !== burger && node !== name) {
					bar.append(node);
				}
			}
			// Each step marked with what it is, for its icon, as Finder marks a disk and its folders:
			// the server (the home mark, which reads its driver's name here), a database, a schema,
			// and where you are — a table, or the schema or database itself.
			const home = bar.querySelector(".ad-breadcrumb-home");
			if (home) {
				home.className = "ad-pathbar-step";
				home.dataset.step = "server";
				home.textContent = home.title;
			}
			for (const link of bar.querySelectorAll("a:not([data-step])")) {
				link.className = "ad-pathbar-step";
				link.dataset.step = new URL(link.href, location.href).searchParams.get(
					"ns",
				)
					? "schema"
					: "database";
			}
			const page = new URLSearchParams(location.search);
			const here = document.createElement("span");
			here.className = "ad-pathbar-step";
			here.setAttribute("aria-current", "page");
			here.dataset.step = [
				"select",
				"table",
				"edit",
				"create",
				"indexes",
				"foreign",
				"trigger",
			].some((key) => page.has(key))
				? "table"
				: page.get("ns")
					? "schema"
					: "database";
			here.textContent =
				name.querySelector(".ad-breadcrumb-name")?.textContent ??
				name.textContent;
			bar.append(here);
			document.body.append(bar);
			document.body.classList.add("ad-has-pathbar");
		}
	};

	// Back and forward at the start of the toolbar, one capsule, as Finder's has them. Greyed where
	// the Navigation API says there is nowhere to go; without it, always offered.
	const historySteps = (breadcrumb) => {
		const steps = document.createElement("div");
		steps.className = "ad-history";
		for (const [step, label] of [
			["back", "Back"],
			["forward", "Forward"],
		]) {
			const button = document.createElement("button");
			button.type = "button";
			button.className = `ad-history-${step}`;
			button.title = strings()[`history.${step}`] ?? label;
			button.setAttribute("aria-label", button.title);
			const can =
				window.navigation?.[step === "back" ? "canGoBack" : "canGoForward"];
			button.disabled = can === false;
			button.addEventListener("click", () => window.history[step]());
			steps.append(button);
		}
		const burger = breadcrumb.querySelector("#menuopen");
		if (burger) {
			burger.after(steps);
		} else {
			breadcrumb.prepend(steps);
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
	let sortDone = false;
	let titled = false;

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
		processlist: "processes",
		variables: "variables",
		status: "status",
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
			// `database=` alters the database a page is in, and on the server's own page, which is in
			// none, creates one.
			const creates =
				param === "database" && !new URLSearchParams(location.search).get("db");
			link.dataset.tool = creates
				? "new"
				: (TOOL_SECTIONS[url.hash] ?? TOOL_PARAMS[param] ?? "");
		}
	};

	const buildToolbar = (breadcrumb) => {
		historySteps(breadcrumb);
		toolbar = document.createElement("div");
		toolbar.className = "ad-toolbar";
		const search = document.createElement("button");
		search.type = "button";
		search.className = "ad-toolbar-search";
		search.textContent = strings()["toolbar.search"] ?? "Search";
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
			if (frameless) {
				buildToolbar(breadcrumb);
			}
		}
		// The server's own page (Select database) has no page in its path to be the toolbar's title,
		// so its heading is, once parsed; the heading then hides as on every other page.
		const heading = breadcrumb?.nextElementSibling;
		if (
			toolbar &&
			!titled &&
			!breadcrumb.querySelector(".ad-breadcrumb-current") &&
			heading?.matches("h2") &&
			heading.nextSibling
		) {
			const title = document.createElement("span");
			title.className = "ad-breadcrumb-current";
			title.textContent = heading.textContent.trim();
			toolbar.before(title);
			titled = true;
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
		// A table's sort, out of its box on the page and into the toolbar as one button with a menu,
		// as Finder's "group by" is (sort-menu.js opens it). The box stays in the form, which posts
		// it, only hidden (window.css); hidden the moment it arrives, so it is never seen.
		const order =
			toolbar &&
			!sortDone &&
			document.querySelector('#form select[name="order[0]"]');
		if (order) {
			order.closest("fieldset")?.classList.add("ad-sort-moved");
			const sort = document.createElement("button");
			sort.type = "button";
			sort.className = "ad-toolbar-sort";
			sort.setAttribute("aria-haspopup", "menu");
			// adminer's own word for it, from the box's legend, which comes before the select.
			sort.title =
				order
					.closest("fieldset")
					?.querySelector("legend")
					?.textContent.trim() || "Sort";
			sort.setAttribute("aria-label", sort.title);
			// Between the page's actions and the search.
			toolbar.querySelector(".ad-toolbar-search")?.before(sort);
			sortDone = true;
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
