/**
 * The pager as a desktop grid has it: first, previous, the page you are on, how many there
 * are, next, last — and paging swaps the rows in place rather than rebuilding the page.
 *
 * Adminer prints a run of page numbers around the current one, with an ellipsis where it
 * skipped some: fine on a web page, but to reach page 7 you first find 7. A list of every page
 * is one click to anywhere, and the arrows are the step-at-a-time case that is most of the
 * paging anyone does.
 *
 * What it knows, it knows from what adminer printed. The page numbers come off the links, the
 * count comes from the highest of them — or from the "last" link's title when the rows could
 * not be counted exactly, which adminer marks with a ~ and so does this. Nothing is invented:
 * with no last link and no numbers beyond the current page, the count is left off entirely.
 */

// Names nothing else here uses: these files are plain scripts, so a top-level const is shared
// with every other one — table-columns.js already owns `table`, and a second would throw before
// this ran at all.
const pagerGrid = document.querySelector("#table");
// The pager is the one box in the row actions holding links of its own — the page numbers.
const pagerBox = [...document.querySelectorAll(".footer fieldset")].find(
	(box) => box.querySelector(":scope > a"),
);

if (pagerGrid && pagerBox) {
	/* --- What page we are on, and how many there are ---------------------------------- */

	const pageOf = (href) =>
		Number(new URL(href, location.href).searchParams.get("page") ?? 0);

	const links = [...pagerBox.querySelectorAll(":scope > a")];
	// Adminer links the last page by number when it counted the rows, and by page=last when it
	// only estimated them — with the estimate in the title, as ~N.
	const estimated = links.find(
		(a) => new URL(a.href, location.href).searchParams.get("page") === "last",
	);
	const numbered = links.map(pageOf).filter((page) => !Number.isNaN(page));

	const at = new URLSearchParams(location.search).get("page");
	const current = Number(at ?? 0) || 0;
	const last = Math.max(
		...numbered,
		estimated ? Number(estimated.title.replace(/\D/g, "")) : 0,
		current,
	);

	/* --- Which rows those pages hold -------------------------------------------------- */

	// How many rows a page is, off the field that sets it (src/Assets/javascript/page-size.js).
	const perPage = Number(
		document.querySelector("#form [name='limit']")?.value ?? 0,
	);
	// How many there are in total, off adminer's own "50 rows" checkbox. It is a translated
	// string, so the number is what is read out of it — and adminer prefixes ~ when it could
	// only estimate, which is carried through rather than dropped.
	//
	// The label's last child, not its text: adminer puts an inline script between the checkbox
	// and the words, and that script has numbers of its own — reading the lot gave "5025050"
	// for fifty rows.
	const counted = document
		.querySelector("#form [name='all'], .footer [name='all']")
		?.closest("label")
		?.lastChild?.textContent.trim();
	const rows = Number((counted ?? "").replace(/\D/g, ""));
	const about = (counted ?? "").includes("~") ? "~" : "";

	/** The rows a page shows, as adminer numbers them: 1-20 of 50. Falls back to the page out
	 * of the pages when the rows were never counted — nothing here is invented. */
	const rangeOf = (page) => {
		if (!perPage || !rows) {
			return String(page + 1);
		}
		const from = page * perPage + 1;
		return `${from}-${Math.min(from + perPage - 1, rows)}`;
	};

	/* --- The url of a page, which is this one with the number swapped ------------------ */

	const urlOf = (page) => {
		const url = new URL(links[0].href, location.href);
		// next= is adminer's other way of paging, by the last row seen rather than by number;
		// a number of our own would be read against it.
		url.searchParams.delete("next");
		url.searchParams.set("page", String(page));
		return url.href;
	};

	/* --- The controls ------------------------------------------------------------------ */

	// Drawn rather than typed. A chevron is a font glyph, and the three platforms this app runs
	// on hand back three different ones — different weight, different width, and "|‹" kerned
	// differently again. Four paths of the same stroke are the same four marks everywhere, at
	// whatever size the row is scaled to, in whatever colour it inherits.
	const ARROWS = {
		first: "M11 3.5 7 8l4 4.5M5 3.5v9",
		previous: "M10 3.5 6 8l4 4.5",
		next: "M6 3.5 10 8l-4 4.5",
		last: "M5 3.5 9 8l-4 4.5M11 3.5v9",
	};

	/** One stroked path in a 16x16 box: every mark in this row is one of these. */
	const mark = (d) => {
		const svg = document.createElementNS("http://www.w3.org/2000/svg", "svg");
		svg.setAttribute("viewBox", "0 0 16 16");
		svg.setAttribute("aria-hidden", "true");
		const path = document.createElementNS("http://www.w3.org/2000/svg", "path");
		path.setAttribute("d", d);
		svg.append(path);
		return svg;
	};

	const arrow = (which, page, title) => {
		const step = document.createElement(page === null ? "span" : "a");
		step.className = "ad-page-step";
		step.append(mark(ARROWS[which]));
		if (page !== null) {
			step.href = urlOf(page);
			step.title = title;
		}
		return step;
	};

	const pages = document.createElement("select");
	pages.className = "ad-page-select";
	pages.title = pagerBox.querySelector("legend")?.textContent.trim() ?? "";

	// A native select brings a border, a background and a chevron of its own, none of which
	// belong in a row of hairline arrows. Stripped to its text (the stylesheet does that) and
	// given the same chevron the arrows are drawn with, laid over it and deaf to the pointer so
	// the whole chip still opens the list.
	const chip = document.createElement("span");
	chip.className = "ad-page-chip";
	chip.append(pages, mark("M4 6.5 8 10.5l4-4"));

	const total = document.createElement("span");
	total.className = "ad-page-total";
	// The word between the rows on screen and the rows there are, translated server-side
	// (AdminerDesktop::head) because this file has no language of its own.
	const word =
		document.querySelector('meta[name="ad-pager-of"]')?.content ?? "/";

	/** Draw the row for the page we are on. Called again after each move, because every part of
	 * it — which arrows lead anywhere, which page is selected — is that number. */
	const render = (page) => {
		// A list of every page, unless there are so many that building it costs more than it
		// saves; adminer's own legend still prompts for a number to jump to.
		pages.replaceChildren();
		if (last < 1000) {
			for (let i = 0; i <= last; i++) {
				pages.append(new Option(rangeOf(i), String(i), false, i === page));
			}
		} else {
			pages.append(new Option(rangeOf(page), String(page), false, true));
		}
		// "of 50" when the rows were counted, "/ 5" when only the pages are known.
		total.textContent =
			perPage && rows
				? `${word} ${about}${rows}`
				: last > 0
					? `/ ${estimated ? "~" : ""}${last + 1}`
					: "";
		pagerBox.replaceChildren(
			pagerBox.querySelector("legend"),
			arrow("first", page > 0 ? 0 : null, "1"),
			arrow("previous", page > 0 ? page - 1 : null, String(page)),
			chip,
			total,
			arrow("next", page < last ? page + 1 : null, String(page + 2)),
			arrow("last", page < last ? last : null, String(last + 1)),
		);
	};

	/* --- Moving between them ----------------------------------------------------------- */

	const goTo = async (page) => {
		render(page); // the pager moves at once; the rows follow when the answer arrives
		if (!(await window.desktopRefresh(urlOf(page)))) {
			return; // it fell back to a real navigation; this page is on its way out
		}
	};

	pagerBox.addEventListener("click", (event) => {
		const step = event.target.closest("a.ad-page-step");
		if (step) {
			event.preventDefault();
			goTo(pageOf(step.href));
		}
	});
	pages.addEventListener("change", () => goTo(Number(pages.value)));

	render(at === "last" ? last : current);
}
