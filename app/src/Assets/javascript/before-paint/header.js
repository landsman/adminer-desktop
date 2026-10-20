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

	const observer = new MutationObserver(() => {
		const breadcrumb = document.querySelector("#content #breadcrumb");
		// Complete once the parser has moved past it: something follows it.
		if (!pathDone && breadcrumb?.nextSibling) {
			if (!ours()) {
				observer.disconnect();
				return;
			}
			rewritePath(breadcrumb);
			pathDone = true;
		}
		const badge = document.querySelector("#ad-connection-badge");
		if (pathDone && badge) {
			placeBadge(badge);
			observer.disconnect();
		}
	});
	observer.observe(document, { childList: true, subtree: true });
	// A page with no badge (the login) has nothing to wait for past its end.
	document.addEventListener("DOMContentLoaded", () => observer.disconnect());
}
