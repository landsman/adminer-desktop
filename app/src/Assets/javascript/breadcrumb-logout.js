/**
 * Put Logout at the end of the breadcrumb's row and the narrow window's burger at its start,
 * so a long path shrinks instead of running under either. A narrow window moves Logout into
 * the sidebar's drawer instead, and the path starts at a home mark rather than the driver's name.
 *
 * Adminer prints Logout as a form of its own, outside the breadcrumb; the adminer-desktop
 * theme pinned it over the panel's corner, and nothing kept the path out of its way. Moved
 * into the row, the two share one line and the theme's flex layout decides who gives (layout.css).
 *
 * Only under that theme — its breadcrumb is the flex row; any other design is left as adminer
 * drew it.
 */

const breadcrumb = document.querySelector("#content #breadcrumb");
// .logout is the <p> inside the form; the form moves, or the button would lose what it submits.
const logout = document.querySelector(".logout")?.closest("form");

if (breadcrumb && logout && getComputedStyle(breadcrumb).display === "flex") {
	// The current page's name is a bare text node, which no selector reaches; in a span it can
	// take an ellipsis too, once the links have given all they can.
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
	}
	// In a narrow window the header row is the burger and the path, and Logout goes in the
	// drawer, under the logo; the same breakpoint as the burger's (layout.css), and live, so
	// resizing the window moves it back.
	const narrow = matchMedia("(max-width: 800px)");
	const heading = document.querySelector("#menu h1");
	const place = () => {
		if (narrow.matches && heading) {
			heading.after(logout);
		} else {
			breadcrumb.append(logout);
		}
	};
	place();
	narrow.addEventListener("change", place);
	// Adminer's burger for a narrow window, which it floats over the corner the breadcrumb
	// occupies here; at the start of the row it is beside the path instead of on top of it.
	const burger = document.querySelector("#menuopen");
	if (burger) {
		breadcrumb.prepend(burger);
	}
}
