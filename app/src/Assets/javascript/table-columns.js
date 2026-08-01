/**
 * Drag the edge of a column header on the data list to set that column's width.
 *
 * Adminer sizes the columns from their contents, which on a table with a json or a text
 * column means one column takes the window and the rest are squeezed into what is left. This
 * hands the decision over: a grip on each header's right edge, dragged like any other column
 * in a file manager or a spreadsheet.
 *
 * The widths live in sessionStorage, not in settings.json — a column width is about the query
 * in front of you, not a preference to carry into next week, and this way a look at one wide
 * table leaves nothing behind. Keyed per table, so widening `payload` on documents says
 * nothing about the next table opened.
 */

// The data list, and only it: adminer gives the results table this id, and every other table
// on the page (the layout tables, the structure list) is left alone.
const table = document.querySelector("#table");
const row = table?.querySelector("tr");
// Every cell of the header row, because the first one is the checkbox and edit column — a <td>
// with no name and no grip, but a column all the same, so the widths add up.
const cells = row ? [...row.children] : [];
const headers = cells.filter((cell) => cell.tagName === "TH");

if (headers.length) {
	const params = new URLSearchParams(location.search);
	const store = `ad-columns:${params.get("db")}.${params.get("ns") ?? ""}.${params.get("select")}`;
	// The column's own name, off its sort link: the header's textContent carries adminer's
	// inline script with it, and an index would follow the wrong column the moment one is hidden.
	const name = (th) => th.querySelector("a")?.textContent.trim() ?? "";
	const MIN = 40;

	const saved = () => {
		try {
			return JSON.parse(sessionStorage.getItem(store) ?? "{}");
		} catch {
			return {}; // unreadable or unavailable: start over rather than break the page
		}
	};

	// Auto layout sizes every column by its widest cell, so setting one width would just push
	// the others around. Measuring them all once and switching to fixed pins the lot, and from
	// then on a drag moves the one column it is on and nothing else.
	//
	// The table's own width is pinned with them: a fixed layout with an auto width goes back to
	// measuring the content and ignores the columns entirely. So the table is told what it adds
	// up to, and told again after every drag — which is also what makes it grow past the window
	// and scroll, rather than the other columns paying for this one.
	const total = () =>
		cells.reduce((sum, cell) => sum + Number.parseFloat(cell.style.width), 0);
	let pinned = false;
	const pin = () => {
		if (!pinned) {
			// The class carries box-sizing: border-box, so a width set here is the width measured
			// back — otherwise padding and borders are added on top and the sum drifts per column.
			table.classList.add("ad-columns-sized");
			for (const cell of cells) {
				cell.style.width = `${cell.getBoundingClientRect().width}px`;
			}
			table.style.tableLayout = "fixed";
			table.style.width = `${total()}px`;
			pinned = true;
		}
	};

	const widths = saved();
	if (Object.keys(widths).length) {
		pin();
		for (const th of headers) {
			if (widths[name(th)]) {
				th.style.width = `${widths[name(th)]}px`;
			}
		}
		table.style.width = `${total()}px`;
	}

	for (const th of headers) {
		const grip = document.createElement("div");
		grip.className = "ad-column-grip";
		th.append(grip);

		// The table's own onclick is adminer's tableClick, which walks up from whatever was
		// clicked to the row it is in and ticks that row's checkbox — on the header row, that is
		// "select every row". A drag ends in a click on the grip like any other press, so the
		// click stops here rather than reaching the table. dblclick too: it is bound as well,
		// and two quick drags of the same edge are otherwise one.
		for (const bubbling of ["click", "dblclick"]) {
			grip.addEventListener(bubbling, (e) => e.stopPropagation());
		}

		let startX = 0;
		let startWidth = 0;
		grip.addEventListener("pointerdown", (e) => {
			pin();
			startX = e.clientX;
			startWidth = th.getBoundingClientRect().width;
			grip.setPointerCapture(e.pointerId);
			// The header is a sort link and the rows are selectable; this is neither.
			e.preventDefault();
		});
		grip.addEventListener("pointermove", (e) => {
			if (grip.hasPointerCapture(e.pointerId)) {
				th.style.width = `${Math.max(MIN, startWidth + e.clientX - startX)}px`;
				table.style.width = `${total()}px`;
			}
		});
		grip.addEventListener("pointerup", (e) => {
			grip.releasePointerCapture(e.pointerId);
			sessionStorage.setItem(
				store,
				JSON.stringify({
					...saved(),
					[name(th)]: Math.round(th.getBoundingClientRect().width),
				}),
			);
		});
	}
}
