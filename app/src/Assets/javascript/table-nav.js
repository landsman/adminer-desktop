/**
 * Open a table's data on a double-click of its name, DataGrip-style.
 *
 * The sidebar's per-row "Select data" link is hidden in the theme, so a double-click on the
 * table name takes its place and navigates to that same select URL. A single click still
 * opens the table's structure, the way Adminer renders it.
 *
 * The table name is a real link, so a double-click also fires a single click first — left
 * alone that loads the structure page and then the data page, which flashed the sidebar
 * between the two. So the single click is held briefly: if a double-click follows it wins
 * and the structure page is never loaded; otherwise the structure opens after the pause.
 */

const DOUBLE_CLICK_MS = 250;
let pendingNav = null;

/**
 * @param {MouseEvent} e
 * @returns {HTMLAnchorElement | null}
 */
function tableName(e) {
	return e.target.closest("#tables li a:not(.select)");
}

/** @param {MouseEvent} e */
function onClick(e) {
	const name = tableName(e);
	if (!name) {
		return;
	}
	e.preventDefault();
	clearTimeout(pendingNav);
	const href = name.href;
	pendingNav = setTimeout(() => {
		location.href = href;
	}, DOUBLE_CLICK_MS);
}

/** @param {MouseEvent} e */
function onDoubleClick(e) {
	const name = tableName(e);
	if (!name) {
		return;
	}
	e.preventDefault();
	clearTimeout(pendingNav);
	const select = name.closest("li").querySelector("a.select");
	if (select) {
		location.href = select.href;
	}
}

// Only when the Adminer Desktop theme is active: the hidden "vypsat" link and the styling
// this pairs with are ours, so on a gallery design leave Adminer's own click behaviour
// alone. --ad-accent is defined only by our theme.
if (
	getComputedStyle(document.documentElement)
		.getPropertyValue("--ad-accent")
		.trim()
) {
	addEventListener("click", onClick);
	addEventListener("dblclick", onDoubleClick);
}
