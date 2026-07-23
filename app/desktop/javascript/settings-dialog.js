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

if (gear && dialog && cancel) {
	gear.onclick = () => dialog.showModal();

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
