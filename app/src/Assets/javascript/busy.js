/**
 * The activity indicator over the page while it waits on the server (css/busy.css), which would
 * otherwise sit unchanged until the answer comes.
 *
 * - A form marked data-busy, or inside an element that is, shows it when it is submitted, and a
 *   link the same when it is followed. Mark only what navigates this page: nothing else takes the
 *   indicator away again.
 * - A script waiting on work of its own calls window.desktopBusy() and desktopBusy(false).
 *
 * A popover, so it lies over an open modal too. Back restores the page as it was left, indicator
 * and all, so pageshow takes it down.
 */

// In a block of its own: these files are classic scripts sharing one global scope.
{
	let indicator = null;

	window.desktopBusy = (on = true) => {
		if (!indicator) {
			indicator = document.createElement("div");
			indicator.id = "ad-busy";
			indicator.popover = "manual";
			indicator.setAttribute("role", "progressbar");
			indicator.setAttribute(
				"aria-label",
				window.desktopLang("busy", "Loading…"),
			);
			indicator.innerHTML = '<span class="ad-spinner"></span>';
			document.body.append(indicator);
		}
		indicator.togglePopover(on);
	};

	// On the document, so a listener nearer the target that stops the navigation is heard first.
	document.addEventListener("submit", (event) => {
		if (!event.defaultPrevented && event.target.closest("[data-busy]")) {
			window.desktopBusy();
		}
	});
	document.addEventListener("click", (event) => {
		const link = event.target.closest?.("a[href]");
		// A modified click, or a link to another window, leaves this page where it is.
		if (
			link?.closest("[data-busy]") &&
			!link.target &&
			!event.defaultPrevented &&
			!event.metaKey &&
			!event.ctrlKey &&
			!event.shiftKey
		) {
			window.desktopBusy();
		}
	});

	addEventListener("pageshow", (event) => {
		if (event.persisted) {
			window.desktopBusy(false);
		}
	});
}
