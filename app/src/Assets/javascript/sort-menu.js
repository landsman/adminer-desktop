/**
 * The table's sort as a toolbar button with a menu, as Finder's "group by" is: None, the table's
 * columns with a check by the one it is sorted by, and descending. before-paint/header.js puts the
 * button in the toolbar and hides adminer's Sort box; that box is still what the form posts, so a
 * pick sets its select and checkbox and submits the form, and adminer sorts as it always has.
 * Only on a table's data, in a window with no title bar (the button is not there otherwise).
 */
// In a block: classic scripts share one global scope (see sidebar-focus.js).
{
	const button = document.querySelector(".ad-toolbar-sort");
	const select = document.querySelector('#form select[name="order[0]"]');
	if (button && select) {
		const form = select.form;
		const fieldset = select.closest("fieldset");
		const desc = fieldset.querySelector('input[name="desc[0]"]');
		// adminer's own words, translated: the checkbox's label; "None" is ours.
		const descLabel =
			desc?.closest("label")?.textContent.trim() || "descending";
		const noneLabel = window.desktopLang("sort.none", "None");

		const menu = document.createElement("div");
		menu.id = "ad-sort-menu";
		menu.popover = "auto";
		menu.setAttribute("role", "menu");
		menu.setAttribute("aria-label", button.title);
		document.body.append(menu);

		// The order it is sorted by is the first row only; the menu replaces the whole of it, as a
		// pick in Finder's does, so the rows adminer adds after it are emptied.
		const sortBy = (column) => {
			for (const other of form.querySelectorAll('select[name^="order["]')) {
				other.value = other === select ? column : "";
			}
			form.requestSubmit();
		};

		const item = (label, checked, pick) => {
			const entry = document.createElement("button");
			entry.type = "button";
			entry.setAttribute("role", "menuitemcheckbox");
			entry.setAttribute("aria-checked", String(checked));
			entry.textContent = label;
			entry.addEventListener("click", () => {
				menu.hidePopover();
				pick();
			});
			return entry;
		};

		const rule = () => {
			const hr = document.createElement("div");
			hr.setAttribute("role", "separator");
			return hr;
		};

		const build = () => {
			const entries = [
				item(noneLabel, select.value === "", () => sortBy("")),
				rule(),
			];
			for (const option of select.options) {
				if (option.value !== "") {
					entries.push(
						item(option.text, select.value === option.value, () =>
							sortBy(option.value),
						),
					);
				}
			}
			if (desc) {
				entries.push(
					rule(),
					item(descLabel, desc.checked, () => {
						desc.checked = !desc.checked;
						if (select.value) {
							form.requestSubmit();
						}
					}),
				);
			}
			menu.replaceChildren(...entries);
		};

		// Under the button, kept inside the window.
		const place = () => {
			const at = button.getBoundingClientRect();
			menu.style.top = `${at.bottom + 6}px`;
			menu.style.left = `${Math.max(8, Math.min(at.left, innerWidth - menu.offsetWidth - 8))}px`;
		};

		button.addEventListener("click", () => {
			build();
			menu.togglePopover();
		});
		menu.addEventListener("toggle", (event) => {
			button.setAttribute("aria-expanded", String(event.newState === "open"));
			if (event.newState === "open") {
				place();
				menu.querySelector('[aria-checked="true"]')?.focus();
			}
		});
	}
}
