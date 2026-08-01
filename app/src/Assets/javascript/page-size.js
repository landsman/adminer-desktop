/**
 * Rows per page as a list to pick from, not a number to type.
 *
 * Adminer's Limit is a number input: to see more rows you select the digits, type new ones and
 * press Select. Every desktop grid — DataGrip's "50 rows" chip, a file manager's view menu —
 * offers the handful of sizes anyone actually uses and applies the one you pick. This is that,
 * on adminer's own field, so nothing else has to know: it keeps the name, so a plain Select
 * still posts it, and the value the page arrived with is always in the list even when it is
 * not one of ours.
 *
 * Changing it submits, rather than swapping the rows in place the way a wider column does.
 * The page size decides how many pages there are, so the pager, the row count and the export
 * count are all answers to it — refreshing the rows alone would leave a footer describing the
 * result before last.
 */

// Adminer prints this field on the select page only, which is the one with rows to size.
const limit = document.querySelector("#form input.size[name='limit']");

if (limit && document.querySelector("#table")) {
	// What a page of rows usually is. Below ten is a scroll bar's worth of nothing; past a
	// thousand the browser is the slow part, not the database.
	const SIZES = [10, 25, 50, 100, 250, 500, 1000];
	const current = Number(limit.value);

	const select = document.createElement("select");
	select.name = limit.name;
	select.className = limit.className;
	for (const size of [...new Set([...SIZES, current])].sort((a, b) => a - b)) {
		if (size > 0) {
			select.append(
				new Option(String(size), String(size), false, size === current),
			);
		}
	}

	// The form is a GET, so this is what pressing Select does with the new number in the field.
	select.addEventListener("change", () => select.form.requestSubmit());
	limit.replaceWith(select);
}
