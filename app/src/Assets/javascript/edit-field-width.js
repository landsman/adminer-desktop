/**
 * Make the edit form's fields as wide as the user wants them, and keep them that way.
 *
 * Adminer sizes every field with a fixed cols='50' — a terminal's idea of wide, not a desktop
 * window's, and thin for a JSON payload. The theme puts their width on --ad-edit-field-width
 * instead (theme/designs/adminer-desktop/forms.css), so there is nothing to build here: a
 * textarea already has a native resize grip, so dragging that *is* the handle. On release the
 * dragged width moves off the one element onto the property, so every field on the form
 * follows it, and is posted to the resize action (src/Api/ResizePreference.php). head() emits
 * it back before paint, so the next row opens at the same width with no jump.
 *
 * The JSON columns are the odd ones: JUSH swaps their textarea for a highlighted
 * <pre contenteditable>, sized once from that textarea and resizable in its own right. So a
 * drag can start on either, and the width is pushed onto the <pre>s explicitly — their inline
 * width outranks any property.
 */

// Only the edit/insert form: its layout table is what the width applies to. #form is also the
// SQL page's, whose textarea is the sqlarea and sized by its own rules.
const fields = document.querySelector("#form > table.layout");

if (fields) {
	const root = document.documentElement;
	// Keep in step with the clamp in src/Api/ResizePreference.php.
	const clamp = (px) => Math.max(240, Math.min(2000, px));
	// A pointer event inside a <pre> lands on one of JUSH's spans, so ask for the field itself.
	const field = (target) => target.closest?.("textarea, pre.jush");

	// The width before the drag. Null when the pointer went down on anything but a field,
	// which is every ordinary click into one.
	let start = null;

	fields.addEventListener("pointerdown", (e) => {
		start = field(e.target)?.offsetWidth ?? null;
	});

	fields.addEventListener("pointerup", (e) => {
		const width = field(e.target)?.offsetWidth;
		// Only a horizontal drag counts: the grip resizes vertically too, and that height is
		// the one field's business, not every field's.
		if (start === null || width === undefined || width === start) {
			start = null;
			return;
		}
		start = null;
		for (const area of fields.querySelectorAll("textarea")) {
			// Hand the width back to the property — the inline one the browser just wrote would
			// otherwise beat it and leave this field the only wide one.
			area.style.width = "";
		}
		for (const pre of fields.querySelectorAll("pre.jush")) {
			// JUSH's own inline width, which no property can reach; set it to match.
			pre.style.width = `${clamp(width)}px`;
		}
		root.style.setProperty("--ad-edit-field-width", `${clamp(width)}px`);
		// Fire-and-forget, like the sidebar's: nothing waits on the answer, and a beacon
		// survives the page being torn down by the very next navigation.
		navigator.sendBeacon(
			window.desktopApi.resize,
			new URLSearchParams({ what: "edit-field", width: String(clamp(width)) }),
		);
	});
}
