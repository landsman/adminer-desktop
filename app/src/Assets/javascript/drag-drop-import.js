/**
 * Turn the whole import page into a dropzone.
 *
 * Adminer's import page keeps its upload behind one small file <input>, so importing a dump
 * means finding that control and clicking through the OS picker. Here the entire window
 * accepts the file: drag it anywhere over the page and drop it, and it lands on Adminer's own
 * `sql_file[]` input — so Adminer's existing multiple-file upload handles it unchanged. The
 * file is only loaded onto the input, never submitted: running a dump is irreversible, so the
 * deliberate Execute click stays with the user.
 *
 * Scoped to the import page alone by the presence of that input; every other page is left
 * untouched. A browser navigates away to display a file dropped on the page, which would throw
 * out the import page — so dragover and drop are cancelled across the whole window, not just
 * over the input.
 */

const input = document.querySelector('input[type="file"][name="sql_file[]"]');

if (input) {
	const overlay = document.createElement("div");
	overlay.id = "ad-import-drop";
	// The label is translated server-side and handed over in a meta by AdminerDesktop::head();
	// fall back to English if it is somehow not there.
	const label = document.querySelector('meta[name="ad-import-drop"]');
	overlay.textContent = label ? label.content : "Drop the SQL file to import";
	// Decoration, and it never takes the pointer (CSS), so it stays out of the drag entirely.
	overlay.setAttribute("aria-hidden", "true");
	document.body.append(overlay);

	// A drag carries files only when its types list says so — a text selection or a link drag
	// leaves the input alone. dataTransfer is absent on some synthetic events, hence the guard.
	const draggingFile = (e) =>
		Array.from(e.dataTransfer?.types ?? []).includes("Files");

	// dragenter/dragleave fire once per element the pointer crosses, so a plain leave handler
	// flickers as the cursor moves between children. Counting enters against leaves tracks
	// whether the drag is still anywhere over the window.
	let depth = 0;
	const hide = () => {
		depth = 0;
		overlay.classList.remove("dragging");
	};

	addEventListener("dragenter", (e) => {
		if (draggingFile(e)) {
			depth++;
			overlay.classList.add("dragging");
		}
	});

	addEventListener("dragover", (e) => {
		// Without this the window is not a drop target, so the drop below never fires and the
		// browser navigates off to show the file instead.
		if (draggingFile(e)) {
			e.preventDefault();
		}
	});

	addEventListener("dragleave", (e) => {
		if (draggingFile(e) && --depth <= 0) {
			hide();
		}
	});

	addEventListener("drop", (e) => {
		if (!draggingFile(e)) {
			return;
		}
		e.preventDefault();
		hide();
		// Hand Adminer's own input the dropped files; its multiple upload and Execute button
		// take it from there. Assigning the drop's FileList is all it takes to fill the input.
		input.files = e.dataTransfer.files;
	});

	// A drag that ends outside the window (dropped elsewhere, or cancelled) leaves the overlay
	// up otherwise — dragleave does not always fire on the way out.
	addEventListener("dragend", hide);
}
