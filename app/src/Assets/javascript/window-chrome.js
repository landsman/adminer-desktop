/**
 * What a title bar would do, on a window that has none.
 *
 * On macOS the launcher takes the title bar away (installFrameless) and the page runs up to the
 * window's top edge, so the band the traffic lights sit in, the logo row and the breadcrumb row
 * are what is left to grab. A press there, but not on a link or a control, hands the drag to the
 * window, and a double-click does what the system's title bar would. Beside the lights sits the
 * button that hides the sidebar, as in Mail; whether it is hidden is stored (Api\SidebarPreference)
 * and set by bodyClass() before the next page paints. Elsewhere, and in a plain browser, the
 * bindings are not there and nothing changes.
 */

if (window.adDragWindow) {
	// The header is the band across the window's top that a title bar would take, as tall as the
	// lights' band in the theme (--ad-titlebar-h): the sidebar's room for the lights and the
	// content's breadcrumb row alike, whichever of them a page shows.
	const band = () =>
		Number.parseFloat(
			getComputedStyle(document.documentElement).getPropertyValue(
				"--ad-titlebar-h",
			),
		) || 0;
	document.addEventListener("mousedown", (event) => {
		const target = event.target;
		if (
			event.button !== 0 ||
			!(target === document.body || event.clientY < band()) ||
			target.closest("a, button, input, select, textarea, label")
		) {
			return;
		}
		// Or the press starts selecting the breadcrumb's text as the window moves.
		event.preventDefault();
		if (event.detail === 2) {
			window.adTitlebarDoubleClick();
		} else {
			window.adDragWindow();
		}
	});

	// Not on the login page: there is no sidebar there to hide.
	if (
		document.querySelector("#foot") &&
		!document.querySelector('input[name="auth[username]"]')
	) {
		const toggle = document.createElement("button");
		toggle.type = "button";
		toggle.id = "ad-sidebar-toggle";
		toggle.title = window.desktopLang(
			"sidebar.toggle",
			"Show or hide the sidebar",
		);
		toggle.setAttribute("aria-label", toggle.title);
		const sync = () =>
			toggle.setAttribute(
				"aria-expanded",
				String(!document.body.classList.contains("ad-sidebar-hidden")),
			);
		sync();
		toggle.addEventListener("click", () => {
			const hidden = document.body.classList.toggle("ad-sidebar-hidden");
			sync();
			navigator.sendBeacon(
				window.desktopApi.sidebar,
				new URLSearchParams({ hidden: hidden ? "1" : "0" }),
			);
		});
		document.body.append(toggle);
	}
}
