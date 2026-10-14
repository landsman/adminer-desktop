/**
 * Search everywhere, on Shift pressed twice — DataGrip's gesture. Type part of a name, Enter goes
 * there. The box is src/Search/search-dialog.latte.
 *
 * What it finds is already on the page, so it asks the server nothing: the sidebar's tables and
 * views, then the databases and schemas in adminer's own db and ns selects.
 */
// In a block, and arrows rather than function declarations: in a classic script a function
// declared in a block still lands on window, so a `select` here failed the whole file against
// another script's top-level one, and an `open` would replace window.open.
{
	const dialog = document.querySelector("#desktop-search");
	const input = dialog?.querySelector("input");
	const list = dialog?.querySelector("[role=listbox]");
	// A screenful; the rest is a few more letters away.
	const LIMIT = 12;
	// Both presses inside this, with no other key between them.
	const DOUBLE_MS = 300;

	// Adminer's URL is driver=…&username=…, then db, ns and the page; everything up to the
	// username is the connection, which every target keeps.
	const connection = () => {
		const keep = new URLSearchParams();
		for (const [key, value] of new URLSearchParams(location.search)) {
			keep.append(key, value);
			if (key === "username") {
				break;
			}
		}
		return keep;
	};

	const url = (params) => {
		const query = connection();
		for (const [key, value] of Object.entries(params)) {
			query.append(key, value);
		}
		return `?${query}`;
	};

	const items = () => {
		const found = [];
		for (const li of document.querySelectorAll("#tables li")) {
			const name = li.querySelector("a.structure, a.view, span")?.textContent;
			const href = li.querySelector("a.select")?.href;
			if (name && href) {
				const kind = li.querySelector("a.view") ? "view" : "table";
				found.push({ name, kind, href });
			}
		}
		const db = new URLSearchParams(location.search).get("db");
		for (const option of document.querySelectorAll(
			"#dbs select[name=db] option",
		)) {
			if (option.value && option.value !== db) {
				found.push({
					name: option.value,
					kind: "database",
					href: url({ db: option.value }),
				});
			}
		}
		const ns = new URLSearchParams(location.search).get("ns");
		for (const option of document.querySelectorAll(
			"#dbs select[name=ns] option",
		)) {
			if (option.value && option.value !== ns) {
				found.push({
					name: option.value,
					kind: "schema",
					href: url({ db, ns: option.value }),
				});
			}
		}
		for (const item of found) {
			item.lower = item.name.toLowerCase();
		}
		return found;
	};

	let all = [];
	let shown = [];
	let active = 0;

	// Substring, case-insensitive; a name that starts with it first, otherwise the sidebar's order.
	const render = () => {
		const query = input.value.trim().toLowerCase();
		const starts = [];
		const contains = [];
		for (const item of all) {
			if (item.lower.startsWith(query)) {
				starts.push(item);
			} else if (item.lower.includes(query)) {
				contains.push(item);
			}
		}
		shown = [...starts, ...contains].slice(0, LIMIT);
		active = 0;
		list.replaceChildren(
			...shown.map((item, i) => {
				const li = document.createElement("li");
				li.id = `desktop-search-${i}`;
				li.setAttribute("role", "option");
				const name = document.createElement("span");
				name.textContent = item.name;
				const kind = document.createElement("small");
				kind.textContent = list.dataset[item.kind];
				li.append(name, kind);
				li.addEventListener("click", () => go(i));
				return li;
			}),
		);
		if (!shown.length) {
			const li = document.createElement("li");
			li.className = "ad-search-none";
			li.textContent = list.dataset.none;
			list.append(li);
		}
		highlight(0);
	};

	const highlight = (i) => {
		const options = list.querySelectorAll("[role=option]");
		if (!options.length) {
			input.removeAttribute("aria-activedescendant");
			return;
		}
		active = (i + options.length) % options.length;
		options.forEach((option, n) => {
			option.setAttribute("aria-selected", String(n === active));
		});
		input.setAttribute("aria-activedescendant", options[active].id);
		options[active].scrollIntoView({ block: "nearest" });
	};

	const go = (i) => {
		if (shown[i]) {
			window.desktopGo(shown[i].href);
		}
	};

	const open = () => {
		all = items();
		input.value = "";
		render();
		window.desktopOpenDialog?.("desktop-search");
		input.focus();
	};

	input?.addEventListener("input", render);
	input?.addEventListener("keydown", (e) => {
		if (e.key === "ArrowDown" || e.key === "ArrowUp") {
			e.preventDefault();
			highlight(active + (e.key === "ArrowDown" ? 1 : -1));
		} else if (e.key === "Enter" && !e.isComposing) {
			e.preventDefault();
			go(active);
		}
	});

	// A capital letter is Shift then the letter, so it resets the count: typing in a field is
	// never two lone Shifts, and the gesture can stay on there too.
	let last = -Infinity;
	// Nor is Shift+click, adminer's range pick over the select page's row checkboxes.
	addEventListener("pointerdown", () => {
		last = -Infinity;
	});
	addEventListener("keydown", (e) => {
		if (e.repeat) {
			return;
		}
		if (e.key !== "Shift" || e.timeStamp - last > DOUBLE_MS) {
			last = e.key === "Shift" ? e.timeStamp : -Infinity;
			return;
		}
		last = -Infinity;
		if (dialog && !document.querySelector("dialog[open]")) {
			open();
		}
	});
}
