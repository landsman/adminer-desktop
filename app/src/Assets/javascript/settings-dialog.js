/**
 * The settings dialog's two buttons.
 *
 * <dialog> brings the backdrop, focus trapping, top-layer stacking and escape-to-close
 * with it, so what is left is opening it, and asking before closing throws edits away.
 *
 * A file rather than the inline script this used to be: inline meant adminer's qsl(),
 * which binds the last matching element in the whole document, so each script had to be
 * printed immediately after its own button. Loaded with defer, both buttons exist by the
 * time this runs and an id says which is which.
 */

const gear = document.querySelector("#desktop-gear");
const dialog = document.querySelector("#desktop-settings");
const cancel = document.querySelector("#desktop-close");
const reset = document.querySelector("#desktop-reset");

// Reset throws away every preference the app stores, so it asks first — and the question is
// translated, so it arrives on the button like the one above.
if (reset) {
	reset.onclick = (event) => {
		if (!confirm(reset.dataset.confirm)) {
			event.preventDefault();
		}
	};
}

// Survive a reload with the dialog as it was — open, on the tab you were reading.
//
// `-dev` reloads the window on every change under app/ (launcher/main.go, watchAndReload), so
// editing the CSS behind an open settings dialog closed it every single time, and the way back
// was gear, tab, scroll. Cmd+R does the same thing deliberately.
//
// Only on a reload, which is the point: adminer is a multi-page app, so restoring on any load
// would reopen the dialog on every link you click. sessionStorage rather than the settings file
// — this is where you were a second ago, not a preference, and it should not outlive the window.
const OPEN_KEY = "ad-settings-open";
// The exception to "reloads only": something inside the dialog navigating on purpose. Changing
// the language POSTs and adminer redirects, which is an ordinary navigation, so the rule above
// would drop the dialog — and that switch lives *in* the dialog, so you are certainly still
// using it. One shot, consumed on read, set through window.adReopenSettings below.
const REOPEN_KEY = "ad-settings-reopen";
const reloaded =
	performance.getEntriesByType("navigation")[0]?.type === "reload";

if (gear && dialog && cancel) {
	const tabs = document.querySelector("#desktop-tabs");
	const remember = () => {
		const tab = tabs?.querySelector("input:checked");
		sessionStorage.setItem(OPEN_KEY, tab ? tab.id : "");
	};

	gear.onclick = () => {
		// showModal() on an open dialog throws InvalidStateError, and since the restore above
		// the gear can be clicked while it is already open — the gear sits behind the backdrop,
		// but a script or a synthetic click still reaches it.
		if (!dialog.open) {
			dialog.showModal();
		}
		remember();
	};
	// Every way out at once: escape, the Cancel button, and submitting the form all fire close.
	dialog.addEventListener("close", () => sessionStorage.removeItem(OPEN_KEY));
	tabs?.addEventListener("change", () => {
		if (dialog.open) {
			remember();
		}
	});

	// For anything in the dialog that navigates deliberately — language.js is the one caller.
	// It records the tab as well, so you come back to the panel you were on.
	window.adReopenSettings = () => {
		remember();
		sessionStorage.setItem(REOPEN_KEY, "1");
	};

	const asked = sessionStorage.getItem(REOPEN_KEY) !== null;
	sessionStorage.removeItem(REOPEN_KEY); // one shot, whether or not it is used
	if (reloaded || asked) {
		const tab = sessionStorage.getItem(OPEN_KEY);
		if (tab !== null) {
			// A tab id that no longer exists (a panel removed between reloads) leaves the
			// default tab checked rather than none of them.
			const input = tab && document.getElementById(tab);
			if (input) {
				input.checked = true;
			}
			dialog.showModal();
		}
	}

	cancel.onclick = () => {
		// Same rule the stylesheet highlights rows by: defaultChecked is the attribute as
		// rendered, checked is what it is now. Radios only count when turned on, since
		// choosing a design necessarily turns the previous one off.
		let changed = 0;
		for (const input of dialog.querySelectorAll("#desktop-panels input")) {
			const edited =
				input.type === "checkbox"
					? input.checked !== input.defaultChecked
					: input.checked && !input.defaultChecked;
			if (edited) {
				changed++;
			}
		}
		for (const select of dialog.querySelectorAll("#desktop-panels select")) {
			if (!select.options[select.selectedIndex].defaultSelected) {
				changed++;
			}
		}
		// The question is translated, so it arrives on the button rather than living here.
		// {n}, not %d: adminer's lang() runs strings through sprintf.
		if (
			!changed ||
			confirm(cancel.dataset.unsaved.replace("{n}", String(changed)))
		) {
			// reset() before closing, or the discarded edits are still sitting there next
			// time the dialog opens, looking like they were kept.
			dialog.close();
			cancel.form.reset();
		}
	};
}
