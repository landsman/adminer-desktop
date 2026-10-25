/**
 * What every modal built on src/Assets/modal.latte does, so no dialog wires it up for itself.
 *
 * - A button with data-dialog="<id>" opens that dialog; one with data-close asks it to close.
 * - Escape, data-close and a click outside are all one close request — requestClose() where the
 *   webview has it, which fires `cancel` just as Escape does — so one guard sees every way out.
 * - A dialog with data-guard asks before a changed form is thrown away. Changed means its fields
 *   differ from when it opened. The guard sits on `close` as well as `cancel`: Chromium ignores
 *   preventDefault() on a second Escape with no click between, and can skip `cancel` right after
 *   the click that opened the dialog — on `close` it is reopened and asked instead, its fields
 *   intact, since closing a dialog does not reset its form.
 */

// In a block of its own: these files are classic scripts sharing one global scope, and a
// top-level `const fields` here collided with edit-field-width.js's and took the whole file down.
{
	const discard = document.querySelector("#ad-discard");
	// The fields as they were when each guarded dialog opened.
	const opened = new WeakMap();
	// Dialogs whose changes the user agreed to throw away, so their close goes through.
	const discarded = new WeakSet();
	// The button a pointer opened each dialog with. WebKit rings a focus set by script as if the
	// keyboard had moved it, so the gear came back from a click still ringed; .ad-pointer-focus
	// hides that ring until the focus leaves it. A key press (detail 0) keeps its ring.
	const pointerOpened = new WeakMap();

	const formOf = (dialog) =>
		dialog.querySelector("form") ?? dialog.closest("form");
	const fields = (dialog) => {
		const form = formOf(dialog);
		return form ? new URLSearchParams(new FormData(form)).toString() : "";
	};
	const changed = (dialog) =>
		dialog.hasAttribute("data-guard") &&
		opened.has(dialog) &&
		!discarded.has(dialog) &&
		fields(dialog) !== opened.get(dialog);

	// data-focus-self: the dialog takes the focus, not its first control. showModal() would honour
	// autofocus on the dialog itself, but not in every webview yet.
	function show(dialog) {
		dialog.showModal();
		if (dialog.hasAttribute("data-focus-self")) {
			dialog.focus();
		}
	}

	function open(dialog) {
		if (!dialog || dialog.open) {
			return;
		}
		discarded.delete(dialog);
		show(dialog);
		opened.set(dialog, fields(dialog));
	}

	function requestClose(dialog) {
		if (dialog.requestClose) {
			dialog.requestClose();
		} else if (changed(dialog)) {
			ask(dialog);
		} else {
			dialog.close();
		}
	}

	// The question, answered by its two buttons. Without the question on the page there is nothing
	// to ask with, so the change is let go rather than trapping the user in the dialog.
	let asking = null;
	function ask(dialog) {
		if (!discard) {
			discarded.add(dialog);
			dialog.close();
			return;
		}
		asking = dialog;
		discard.showModal();
	}
	discard?.querySelector("[data-discard]")?.addEventListener("click", () => {
		const dialog = asking;
		asking = null;
		discard.close();
		if (dialog) {
			discarded.add(dialog);
			formOf(dialog)?.reset();
			dialog.close();
		}
	});

	for (const dialog of document.querySelectorAll("dialog.ad-modal")) {
		if (dialog === discard) {
			continue;
		}
		dialog.addEventListener("cancel", (event) => {
			if (changed(dialog)) {
				event.preventDefault();
				ask(dialog);
			}
		});
		dialog.addEventListener("close", () => {
			if (changed(dialog)) {
				show(dialog);
				ask(dialog);
				return;
			}
			const opener = pointerOpened.get(dialog);
			pointerOpened.delete(dialog);
			if (opener) {
				opener.classList.add("ad-pointer-focus");
				opener.addEventListener(
					"blur",
					() => opener.classList.remove("ad-pointer-focus"),
					{ once: true },
				);
			}
		});
		// A post that saves is the one way out that does not ask.
		formOf(dialog)?.addEventListener("submit", () => discarded.add(dialog));
	}

	// closedby="any" turns a click outside into a close request. Where the webview does not know it,
	// that click lands on the dialog element itself — its content is all inside it.
	if (!("closedBy" in HTMLDialogElement.prototype)) {
		for (const dialog of document.querySelectorAll(
			'dialog.ad-modal[closedby="any"]',
		)) {
			dialog.addEventListener("click", (event) => {
				if (event.target === dialog) {
					requestClose(dialog);
				}
			});
		}
	}

	document.addEventListener("click", (event) => {
		const opener = event.target.closest?.("[data-dialog]");
		if (opener) {
			// WebKit on macOS does not focus a button it clicks, so the dialog had nothing to hand the
			// focus back to when it closed.
			opener.focus();
			const dialog = document.getElementById(opener.dataset.dialog);
			if (event.detail > 0) {
				pointerOpened.set(dialog, opener);
			}
			open(dialog);
			return;
		}
		const closer = event.target.closest?.("[data-close]");
		const dialog = closer?.closest("dialog");
		if (dialog === discard) {
			asking = null;
			discard.close();
		} else if (dialog) {
			requestClose(dialog);
		}
	});

	// For a script that opens a dialog without a button to click — shortcuts.js on ?.
	window.desktopOpenDialog = (id) => open(document.getElementById(id));

	// Opened already, before the first paint (before-paint/open-on-load.js): the guard still needs
	// its fields as they were.
	for (const marker of document.querySelectorAll("[data-open-dialog]")) {
		const dialog = document.getElementById(marker.dataset.openDialog);
		if (dialog?.open) {
			opened.set(dialog, fields(dialog));
		} else {
			open(dialog);
		}
	}
}
