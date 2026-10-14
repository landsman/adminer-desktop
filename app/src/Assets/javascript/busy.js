/**
 * The activity indicator over the page while it waits on the server (css/busy.css), which would
 * otherwise sit unchanged until the answer comes.
 *
 * - A form marked data-busy, or inside an element that is, shows it when it is submitted, and a
 *   link the same when it is followed. Mark only what navigates this page: nothing else takes the
 *   indicator away again.
 * - A script waiting on work of its own calls window.desktopBusy() and desktopBusy(false).
 * - A script that navigates calls window.desktopGo(href) rather than setting location.
 * - The database and schema menus show it as one is changed.
 *
 * The indicator is the page's own (src/Assets/busy.latte, printed in the sidebar), moved to the end
 * of <body> so a sidebar hidden in a narrow window cannot hide it with it. A popover, so it lies
 * over an open modal too. Back restores the page as it was left, indicator and all, so pageshow
 * takes it down.
 *
 * The form is sent, and the link followed, only once the indicator has been drawn: WKWebView
 * draws nothing more of a page after it starts navigating away, so one shown in the same moment
 * never appeared. What it has drawn by then keeps moving: its fade-in and its turn are CSS
 * animations of opacity and transform, which run in the compositor, not the page.
 */

// In a block of its own: these files are classic scripts sharing one global scope.
{
	const indicator = document.getElementById("ad-busy");
	if (indicator) {
		document.body.append(indicator);
	}

	window.desktopBusy = (on = true) => indicator?.togglePopover(on);

	// Two frames: the first draws the indicator, the second starts once that frame is out.
	const afterPaint = (then) =>
		requestAnimationFrame(() => requestAnimationFrame(then));

	window.desktopGo = (href) => {
		window.desktopBusy();
		afterPaint(() => location.assign(href));
	};

	// Adminer sends these menus by form.submit() as one changes, which fires no submit event to
	// wait on, so the change is taken here, ahead of its handler, and the form sent once the
	// indicator is drawn. requestSubmit() still goes through adminer's own GET serialisation.
	document.addEventListener(
		"change",
		(event) => {
			const form = event.target.closest?.("#dbs")?.closest("form");
			if (form) {
				event.stopPropagation();
				window.desktopBusy();
				afterPaint(() => form.requestSubmit());
			}
		},
		true,
	);

	// On the document, so a listener nearer the target that stops the navigation is heard first.
	// The form goes again with the same button, which a form.submit() would leave out, and the
	// second time round it is let through.
	const resent = new WeakSet();
	document.addEventListener("submit", (event) => {
		const form = event.target;
		if (resent.delete(form)) {
			if (event.defaultPrevented) {
				window.desktopBusy(false);
			}
			return;
		}
		if (!event.defaultPrevented && form.closest("[data-busy]")) {
			event.preventDefault();
			window.desktopBusy();
			afterPaint(() => {
				resent.add(form);
				form.requestSubmit(event.submitter);
			});
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
			event.preventDefault();
			window.desktopGo(link.href);
		}
	});

	addEventListener("pageshow", (event) => {
		if (event.persisted) {
			window.desktopBusy(false);
		}
	});
}
