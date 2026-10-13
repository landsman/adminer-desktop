/**
 * What the settings dialog does beyond every other modal: Reset asks first, and the dialog
 * survives a reload. Opening it, closing it and asking about unsaved edits are modal.js's.
 *
 * A file rather than the inline script this used to be: inline meant adminer's qsl(),
 * which binds the last matching element in the whole document, so each script had to be
 * printed immediately after its own button. Loaded with defer, both buttons exist by the
 * time this runs and an id says which is which.
 */

const gear = document.querySelector("#desktop-gear");
const dialog = document.querySelector("#desktop-settings");
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

if (gear && dialog) {
	const tabs = document.querySelector("#desktop-tabs");
	const remember = () => {
		const tab = tabs?.querySelector("input:checked");
		sessionStorage.setItem(OPEN_KEY, tab ? tab.id : "");
	};

	// modal.js opens it, through the gear's data-dialog.
	gear.addEventListener("click", remember);
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
			// Through the gear, so modal.js snapshots the fields and the guard works.
			gear.click();
		}
	}
}
