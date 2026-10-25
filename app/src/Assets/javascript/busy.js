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

	window.adminerDesktop.busy = (on = true) => indicator?.togglePopover(on);

	// Stop, or Escape, drops the wait as a browser's Stop does: a saved connection whose server
	// never answers otherwise turns until the connection times out. The server is not held up by it,
	// adminer lets the session go before it connects (patches/adminer-connect-without-session-lock.patch).
	// desktop-stop reaches what window.stop() does not: rows fetched in place (refresh.js).
	const stop = () => {
		window.stop();
		window.desktopBusy(false);
		dispatchEvent(new Event("desktop-stop"));
	};
	indicator?.querySelector(".ad-busy-stop")?.addEventListener("click", stop);
	addEventListener("keydown", (event) => {
		if (event.key === "Escape" && indicator?.matches(":popover-open")) {
			stop();
		}
	});

	// A form with data-busy-here shows its own wait, by the ad-waiting class, in place of the
	// indicator over the page: the login, whose modal offers a Cancel meanwhile (connections.js).
	const waitOn = (form, on = true) =>
		form.hasAttribute("data-busy-here")
			? form.classList.toggle("ad-waiting", on)
			: window.adminerDesktop.busy(on);

	// Two frames: the first draws the indicator, the second starts once that frame is out.
	const afterPaint = (then) =>
		requestAnimationFrame(() => requestAnimationFrame(then));

	window.adminerDesktop.go = (href) => {
		window.adminerDesktop.busy();
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
				window.adminerDesktop.busy();
				afterPaint(() => form.requestSubmit());
			}
		},
		true,
	);

	// A form in adminer's content goes to another page, so it needs no data-busy, but for one sent
	// to another window (a Ctrl+click) and an export saved to a file, which is a download: any
	// output but "text", sent by the export page's one button or by an Export beside the rows.
	const download = (form, submitter) => {
		const output = form.elements.output?.value;
		return (
			output &&
			output !== "text" &&
			(!submitter?.name || submitter.name === "export")
		);
	};
	const leavesBy = (form, submitter) =>
		form.closest("[data-busy], #content") &&
		form.method !== "dialog" &&
		!form.target &&
		!download(form, submitter);

	// Taken while it is captured, ahead of adminer's own handler, which sends a GET form itself
	// (bodySubmit). The form goes again with the same button, which a form.submit() would leave
	// out, and the second time round every handler hears it.
	const resent = new WeakSet();
	document.addEventListener(
		"submit",
		(event) => {
			const form = event.target;
			if (!resent.has(form) && leavesBy(form, event.submitter)) {
				event.preventDefault();
				event.stopImmediatePropagation();
				waitOn(form);
				afterPaint(() => {
					// Cancelled in the meantime: the wait it shows is already gone.
					if (
						form.hasAttribute("data-busy-here") &&
						!form.classList.contains("ad-waiting")
					) {
						return;
					}
					resent.add(form);
					form.requestSubmit(event.submitter);
				});
			}
		},
		true,
	);
	// A POST a handler stopped the second time round stays on this page. A stopped GET does not:
	// that is adminer sending it.
	document.addEventListener("submit", (event) => {
		const form = event.target;
		if (
			resent.delete(form) &&
			event.defaultPrevented &&
			form.method === "post"
		) {
			waitOn(form, false);
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
			window.adminerDesktop.go(link.href);
		}
	});

	addEventListener("pageshow", (event) => {
		if (event.persisted) {
			window.adminerDesktop.busy(false);
			for (const form of document.querySelectorAll(".ad-waiting")) {
				form.classList.remove("ad-waiting");
			}
		}
	});
}
