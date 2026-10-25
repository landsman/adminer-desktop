/**
 * Type into a long select to narrow it to the options holding those letters, anywhere in the
 * name. A native select only jumps to the first option starting with what is typed, and only
 * while it is typed fast, so in a server's forty databases or a wide table's columns reaching
 * one still meant scrolling.
 *
 * Only where it pays: adminer's database and schema switches, which are for getting somewhere,
 * and a select of MIN options or more. A short one stays native, with its own keyboard and
 * accessibility. The select stays on the page and keeps the value; a pick sets it and fires
 * change, so adminer's handlers, busy.js and the form never know a different menu was used.
 */
// In a block, like search.js: a classic script's top-level names are shared with every other.
{
	// ponytail: a count, so a short select of very long names stays native.
	const MIN = 15;

	const wanted = (select) =>
		!select.multiple &&
		!select.disabled &&
		(select.length >= MIN || select.closest("#dbs") !== null);

	// A combobox over a listbox, the active option named by aria-activedescendant so the focus
	// never leaves the field — the search box's pattern. Unnamed, the placeholder names it.
	const popup = document.createElement("div");
	popup.className = "ad-select-filter";
	popup.setAttribute("popover", "manual");
	const input = document.createElement("input");
	input.type = "text";
	input.placeholder = window.adminerDesktop.lang(
		"select_filter.placeholder",
		"Type part of a name",
	);
	input.autocomplete = "off";
	input.spellcheck = false;
	input.setAttribute("autocapitalize", "off");
	input.setAttribute("role", "combobox");
	input.setAttribute("aria-expanded", "true");
	input.setAttribute("aria-autocomplete", "list");
	input.setAttribute("aria-controls", "ad-select-filter-list");
	const list = document.createElement("ul");
	list.id = "ad-select-filter-list";
	list.setAttribute("role", "listbox");
	popup.append(input, list);

	let select = null;
	let shown = [];
	let active = 0;

	const highlight = (i) => {
		const rows = list.querySelectorAll("[role=option]");
		if (!rows.length) {
			input.removeAttribute("aria-activedescendant");
			return;
		}
		active = (i + rows.length) % rows.length;
		rows.forEach((row, n) => {
			row.setAttribute("aria-selected", String(n === active));
		});
		input.setAttribute("aria-activedescendant", rows[active].id);
		rows[active].scrollIntoView({ block: "nearest" });
	};

	// The select's own order, its optgroup labels kept as headings over what is left of them.
	const render = () => {
		const query = input.value.trim().toLowerCase();
		shown = [...select.options].filter(
			(option) => !option.disabled && option.text.toLowerCase().includes(query),
		);
		const rows = [];
		let group = null;
		shown.forEach((option, i) => {
			const parent = option.parentElement;
			if (parent.tagName === "OPTGROUP" && parent !== group) {
				const heading = document.createElement("li");
				heading.className = "ad-select-filter-group";
				heading.setAttribute("role", "presentation");
				heading.textContent = parent.label;
				rows.push(heading);
			}
			group = parent;
			const row = document.createElement("li");
			row.id = `ad-select-filter-${i}`;
			row.setAttribute("role", "option");
			// An empty option, adminer's "none", still needs a row to click.
			row.textContent = option.text || " ";
			row.addEventListener("click", () => pick(i));
			row.addEventListener("mousemove", () => {
				if (active !== i) {
					highlight(i);
				}
			});
			rows.push(row);
		});
		if (!shown.length) {
			const none = document.createElement("li");
			none.className = "ad-select-filter-none";
			none.textContent = window.adminerDesktop.lang(
				"select_filter.none",
				"Nothing matches",
			);
			rows.push(none);
		}
		list.replaceChildren(...rows);
		highlight(
			query ? 0 : Math.max(0, shown.indexOf(select.selectedOptions[0])),
		);
	};

	// Fixed under the select, or over it when the window ends first; a scroll or a resize moves
	// it along rather than leaving it pointing at nothing.
	const place = () => {
		const rect = select.getBoundingClientRect();
		const below = innerHeight - rect.bottom;
		const up = below < 200 && rect.top > below;
		popup.style.top = up ? "" : `${rect.bottom + 2}px`;
		popup.style.bottom = up ? `${innerHeight - rect.top + 2}px` : "";
		popup.style.left = `${rect.left}px`;
		popup.style.minWidth = `${rect.width}px`;
		popup.style.maxWidth = `${innerWidth - rect.left - 8}px`;
		popup.style.setProperty("--ad-room", `${(up ? rect.top : below) - 10}px`);
	};

	const close = (refocus) => {
		const was = select;
		if (!was) {
			return;
		}
		select = null;
		popup.remove();
		if (refocus) {
			was.focus();
		}
	};

	const open = (target, typed = "") => {
		select = target;
		input.value = typed;
		// Inside a modal dialog, or the dialog makes it inert; a popover is in the top layer
		// either way, so the dialog's own box does not clip it.
		(target.closest("dialog") ?? document.body).append(popup);
		popup.showPopover?.();
		place();
		render();
		input.focus();
	};

	const pick = (i) => {
		const option = shown[i];
		const target = select;
		close(true);
		if (option && !option.selected) {
			option.selected = true;
			target.dispatchEvent(new Event("input", { bubbles: true }));
			target.dispatchEvent(new Event("change", { bubbles: true }));
		}
	};

	input.addEventListener("input", render);
	input.addEventListener("keydown", (e) => {
		if (e.key === "ArrowDown" || e.key === "ArrowUp") {
			e.preventDefault();
			highlight(active + (e.key === "ArrowDown" ? 1 : -1));
		} else if (e.key === "Enter" && !e.isComposing) {
			e.preventDefault();
			pick(active);
		} else if (e.key === "Escape") {
			// Only this menu: not the dialog it sits in.
			e.preventDefault();
			e.stopPropagation();
			close(true);
		} else if (e.key === "Tab") {
			close(true);
		}
	});
	input.addEventListener("focusout", () => close(false));
	// A click on a row must not take the focus from the field, which would close it first.
	list.addEventListener("mousedown", (e) => e.preventDefault());
	addEventListener("scroll", () => select && place(), true);
	addEventListener("resize", () => select && place());

	// Prevented, the mousedown never opens the native menu. A second click closes ours.
	document.addEventListener("mousedown", (e) => {
		const target = e.target.closest?.("select");
		if (e.button !== 0 || !target || !wanted(target)) {
			return;
		}
		e.preventDefault();
		if (select === target) {
			close(true);
		} else {
			open(target);
		}
	});

	// From the keyboard: a letter starts the filter with it, Space or an arrow opens it empty.
	document.addEventListener("keydown", (e) => {
		const target = e.target;
		if (
			target.tagName !== "SELECT" ||
			!wanted(target) ||
			e.metaKey ||
			e.ctrlKey ||
			e.altKey ||
			e.isComposing
		) {
			return;
		}
		const letter = e.key.length === 1 && e.key !== " ";
		if (letter || ["ArrowDown", "ArrowUp", " "].includes(e.key)) {
			e.preventDefault();
			open(target, letter ? e.key : "");
		}
	});
}
