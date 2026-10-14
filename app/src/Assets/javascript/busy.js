/**
 * The activity indicator over the page while it waits on the server. Which waits show it, what a
 * new one uses, and why each navigation here waits for it to be drawn: ../busy.md.
 *
 * The indicator is the page's own (src/Assets/busy.latte, printed in the sidebar), moved to the end
 * of <body> so a sidebar hidden in a narrow window cannot hide it with it. A popover, so it lies
 * over an open modal too. Back restores the page as it was left, indicator and all, so pageshow
 * takes it down.
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

	// A link in adminer's content goes to another page, so it needs no data-busy; the two that do
	// not are a download (a blob's cell) and an anchor on this page (#routines, a query's error).
	const leaves = (link) =>
		link.closest("[data-busy]") ||
		(link.closest("#content") &&
			!new URLSearchParams(link.search).has("download") &&
			!(
				link.hash &&
				link.pathname + link.search === location.pathname + location.search
			));
	document.addEventListener("click", (event) => {
		const link = event.target.closest?.("a[href]");
		// A modified click, or a link to another window, leaves this page where it is.
		if (
			link &&
			leaves(link) &&
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
