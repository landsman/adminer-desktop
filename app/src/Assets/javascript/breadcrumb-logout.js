/**
 * Put Logout at the end of the breadcrumb's row and the narrow window's burger at its start,
 * so a long path shrinks instead of running under either.
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
	breadcrumb.append(logout);
	// Adminer's burger for a narrow window, which it floats over the corner the breadcrumb
	// occupies here; at the start of the row it is beside the path instead of on top of it.
	const burger = document.querySelector("#menuopen");
	if (burger) {
		breadcrumb.prepend(burger);
	}
}
