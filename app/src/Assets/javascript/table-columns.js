/**
 * Drag the edge of a column on the data list to set its width.
 *
 * Adminer sizes the columns from their contents, which on a table with a json or a text
 * column means one column takes the window and the rest are squeezed into what is left. This
 * hands the decision over: a grip down the right edge of each column, dragged like any other
 * column in a file manager or a spreadsheet, and the values re-fetched at the length the new
 * width can show.
 *
 * The widths live in sessionStorage, not in settings.json — a column width is about the query
 * in front of you, not a preference to carry into next week, and this way a look at one wide
 * table leaves nothing behind. Keyed per table, so widening `payload` on documents says
 * nothing about the next table opened.
 *
 * Reading order: the four groups below are what a width has to survive — where it is kept, how
 * the table is made to hold it, how much text is worth fetching into it, and the grip itself.
 * The last dozen lines are the whole thing put together.
 */

// The data list, and only it: adminer gives the results table this id, and every other table
// on the page (the layout tables, the structure list) is left alone.
const table = document.querySelector("#table");
const headerRow = table?.querySelector("tr");
// Every cell of the header row, because the first one is the checkbox and edit column — a <td>
// with no name and no grip, but a column all the same, so the widths add up.
const cells = headerRow ? [...headerRow.children] : [];
const headers = cells.filter((cell) => cell.tagName === "TH");

if (headers.length) {
	const MIN_WIDTH = 40;

	/* --- Where a width is kept ------------------------------------------------------- */

	const store = (() => {
		const params = new URLSearchParams(location.search);
		return `ad-columns:${params.get("db")}.${params.get("ns") ?? ""}.${params.get("select")}`;
	})();

	/** The column's own name, off its sort link: the header's textContent carries adminer's
	 * inline script with it, and an index would follow the wrong column the moment one is
	 * hidden. */
	const columnName = (th) => th.querySelector("a")?.textContent.trim() ?? "";

	/** @returns every width stored for this table, name => pixels. */
	const savedWidths = () => {
		try {
			return JSON.parse(sessionStorage.getItem(store) ?? "{}");
		} catch {
			return {}; // unreadable or unavailable: start over rather than break the page
		}
	};

	const rememberWidth = (th, width) => {
		sessionStorage.setItem(
			store,
			JSON.stringify({ ...savedWidths(), [columnName(th)]: Math.round(width) }),
		);
	};

	/* --- Making the table hold one ---------------------------------------------------- */

	/** What the columns come to, which is what the table has to be told it is. */
	const totalWidth = () =>
		cells.reduce((sum, cell) => sum + Number.parseFloat(cell.style.width), 0);

	/** Freeze every column at the width it happens to have, once.
	 *
	 * Auto layout sizes each column by its widest cell, so setting one width would just push
	 * the others around. Measuring them all and switching to fixed pins the lot, and from then
	 * on a drag moves the one column it is on and nothing else.
	 *
	 * The table's own width is pinned with them: a fixed layout with an auto width goes back to
	 * measuring the content and ignores the columns entirely. So it is told what they add up
	 * to — which is also what makes it grow past the window and scroll, rather than the other
	 * columns paying for this one. */
	let pinned = false;
	const pinColumns = () => {
		if (!pinned) {
			// The class carries box-sizing: border-box, so a width set here is the width measured
			// back — otherwise padding and borders are added on top and the sum drifts per column.
			table.classList.add("ad-columns-sized");
			for (const cell of cells) {
				cell.style.width = `${cell.getBoundingClientRect().width}px`;
			}
			table.style.tableLayout = "fixed";
			pinned = true;
		}
		table.style.width = `${totalWidth()}px`;
	};

	const setWidth = (th, width) => {
		th.style.width = `${Math.max(MIN_WIDTH, width)}px`;
		table.style.width = `${totalWidth()}px`;
	};

	/** Open at what the last drag left, if anything. */
	const restoreWidths = () => {
		const widths = savedWidths();
		if (Object.keys(widths).length) {
			pinColumns();
			for (const th of headers) {
				if (widths[columnName(th)]) {
					setWidth(th, widths[columnName(th)]);
				}
			}
		}
	};

	/* --- Enough text to fill it -------------------------------------------------------- */

	const lengthField = document.querySelector("input[name='text_length']");

	/** How wide one character is in this column, measured in one of its own cells: a json value
	 * is rendered monospace and a plain one is not, and a character of each is a different
	 * number of pixels. Measured rather than assumed for the same reason — the density and
	 * scaling preferences move it too. */
	const characterWidth = (th) => {
		const cell = table.querySelector("tbody tr")?.cells[th.cellIndex];
		if (!cell) {
			return 0;
		}
		const probe = document.createElement("span");
		probe.textContent = "0".repeat(100);
		probe.style.cssText = "position:absolute;visibility:hidden;white-space:pre";
		cell.append(probe);
		const width = probe.getBoundingClientRect().width / 100;
		probe.remove();
		return width;
	};

	/** A wider column is no use if the value was already cut to fit the old one: adminer
	 * shortens every text and json value server-side to the Text length box, so past that point
	 * a drag only buys whitespace. Widen a column past what that allows and the box is raised
	 * to what the new width holds, and the rows fetched again to fill it. */
	const askForEnoughText = (th) => {
		const perCharacter = characterWidth(th);
		if (!lengthField || perCharacter <= 0) {
			return;
		}
		const fits = Math.ceil(th.getBoundingClientRect().width / perCharacter);
		// Only ever up: this is one setting for every column, so following a narrowed column
		// back down would cut the values in all the others.
		if (fits > Number(lengthField.value)) {
			lengthField.value = String(fits);
			// The query has to run again for the longer values, but nobody asked for a new page:
			// refresh.js swaps the rows in where they are, highlighting and all.
			window.desktopRefresh();
		}
	};

	/* --- The grip ---------------------------------------------------------------------- */

	const panel = table.closest("#content");
	// Adminer's own row actions, stuck to the bottom of the panel and floating over the last
	// rows: the list ends where this begins, whatever the table's own box says. Its margin
	// counts as part of it — the footer paints a shadow of solid background up through that gap
	// to hide the rows passing behind, and a grip drawn over that reads as one hanging in air.
	const footer = document.querySelector(".footer");
	const footerMargin = footer
		? Number.parseFloat(getComputedStyle(footer).marginTop)
		: 0;

	/** Run each grip from the header down the column rather than down the header, so the edge is
	 * grabbable next to whichever row is being read. It hangs off the header cell, which adminer
	 * makes sticky, so it rides down with the header as the list scrolls; what changes is how
	 * far it then has to reach, hence measuring rather than setting once. It stops at whichever
	 * comes first below it — the end of the table, the bottom of the panel, or the top of the
	 * row actions — so it never runs past the list it belongs to and over the buttons. */
	const stretchGrips = () => {
		const ends = [table.getBoundingClientRect().bottom];
		if (panel) {
			ends.push(panel.getBoundingClientRect().bottom);
		}
		if (footer) {
			ends.push(footer.getBoundingClientRect().top - footerMargin);
		}
		const height = Math.min(...ends) - headers[0].getBoundingClientRect().top;
		for (const grip of table.querySelectorAll(".ad-column-grip")) {
			grip.style.height = `${Math.max(0, height)}px`;
		}
	};

	const addGrip = (th) => {
		const grip = document.createElement("div");
		grip.className = "ad-column-grip";
		th.append(grip);

		// The table's own onclick is adminer's tableClick, which walks up from whatever was
		// clicked to the row it is in and ticks that row's checkbox — on the header row, that is
		// "select every row". A drag ends in a click on the grip like any other press, so the
		// click stops here rather than reaching the table. dblclick too: it is bound as well, and
		// two quick drags of the same edge are otherwise one.
		for (const bubbling of ["click", "dblclick"]) {
			grip.addEventListener(bubbling, (event) => event.stopPropagation());
		}

		let startX = 0;
		let startWidth = 0;
		grip.addEventListener("pointerdown", (event) => {
			pinColumns();
			startX = event.clientX;
			startWidth = th.getBoundingClientRect().width;
			grip.setPointerCapture(event.pointerId);
			// The header is a sort link and the rows are selectable; this is neither.
			event.preventDefault();
		});
		grip.addEventListener("pointermove", (event) => {
			if (grip.hasPointerCapture(event.pointerId)) {
				setWidth(th, startWidth + event.clientX - startX);
			}
		});
		grip.addEventListener("pointerup", (event) => {
			grip.releasePointerCapture(event.pointerId);
			rememberWidth(th, th.getBoundingClientRect().width);
			askForEnoughText(th);
		});
	};

	/* --- All of it ---------------------------------------------------------------------- */

	restoreWidths();
	for (const th of headers) {
		addGrip(th);
	}
	stretchGrips();
	// Scrolling moves the sticky header down the table, so the reach to its last row changes
	// with it; the window is what sizes the panel the reach is capped by.
	panel?.addEventListener("scroll", stretchGrips, { passive: true });
	window.addEventListener("resize", stretchGrips);
}
