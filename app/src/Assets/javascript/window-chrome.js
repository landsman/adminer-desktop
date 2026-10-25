/**
 * What a title bar would do, on a window that has none.
 *
 * On macOS the launcher takes the title bar away (installFrameless) and the page runs up to the
 * window's top edge, so the band across its top — the sidebar's room for the traffic lights, the
 * content's header row — is what is left to grab. A press there, but not on a control, hands the
 * drag to the window, and a double-click does what the system's title bar would. Beside the
 * lights sits the button that hides the sidebar, as in Mail; whether it is hidden is stored
 * (Api\SidebarPreference) and set by bodyClass() before the next page paints. Elsewhere, and in
 * a plain browser, the bindings are not there and nothing changes.
 */
// In a block: classic scripts share one global scope (see sidebar-focus.js).
{
	const root = document.documentElement;
	// Our design's band (--ad-titlebar-h, window.css), or the 52px css/frameless.css keeps clear
	// under a gallery design, which does not define it.
	const band = () =>
		Number.parseFloat(
			getComputedStyle(root).getPropertyValue("--ad-titlebar-h"),
		) || 52;
	// Whatever takes a press of its own: a drag there would swallow it.
	const CONTROLS =
		"a, button, input, select, textarea, label, summary, [role=button], [tabindex], [contenteditable], dialog";

	// The toolbar's hairline shows only once the page scrolls under it, or while the window is not
	// the active one, as Mail's does (window.css): two classes on <html> to draw it by.
	if (window.adminer.native?.dragWindow) {
		const content = document.querySelector("#content");
		const scrolled = () =>
			root.classList.toggle(
				"ad-content-scrolled",
				(content?.scrollTop ?? 0) > 0,
			);
		content?.addEventListener("scroll", scrolled, { passive: true });
		scrolled();
		const active = () =>
			root.classList.toggle("ad-window-inactive", !document.hasFocus());
		addEventListener("focus", active);
		addEventListener("blur", active);
		active();
	}

	if (window.adminer.native?.dragWindow) {
		document.addEventListener("mousedown", (event) => {
			const target = event.target;
			if (
				event.button !== 0 ||
				// Full screen has no title bar to stand in for, and a dialog's backdrop closes it.
				root.classList.contains("ad-fullscreen") ||
				document.querySelector("dialog[open]") ||
				!(target === document.body || event.clientY < band()) ||
				target.closest(CONTROLS)
			) {
				return;
			}
			// Or the press starts selecting the breadcrumb's text as the window moves.
			event.preventDefault();
			if (event.detail === 2) {
				window.adminer.native?.titlebarDoubleClick();
			} else {
				window.adminer.native?.dragWindow();
			}
		});
	}

	// Our design only, which draws it (window.css); not on the login page, which has no sidebar.
	if (
		window.adminer.native?.dragWindow &&
		!root.classList.contains("ad-frameless-foreign") &&
		document.querySelector("#foot") &&
		!document.querySelector('input[name="auth[username]"]')
	) {
		const toggle = document.createElement("button");
		toggle.type = "button";
		toggle.id = "ad-sidebar-toggle";
		toggle.title = window.adminer.page.lang(
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
				window.adminer.page.api.sidebar,
				new URLSearchParams({ hidden: hidden ? "1" : "0" }),
			);
		});
		document.body.append(toggle);
		// The gear joins it, out of the sidebar's list: the list fades out under the lights' band
		// (window.css), and a mask reaches a fixed child too, so the gear faded with it. Only while
		// the window is wide enough for the band; a narrow one keeps the gear in the drawer, where
		// it is put back. settings-dialog.js finds it by its id, wherever it is.
		const gear = document.querySelector("#desktop-gear");
		if (gear) {
			const home = [gear.parentNode, gear.nextSibling];
			const wide = matchMedia("(min-width: 801px)");
			const place = () => {
				if (wide.matches) {
					document.body.append(gear);
				} else {
					home[0].insertBefore(gear, home[1]);
				}
			};
			place();
			wide.addEventListener("change", place);
		}
	}
}
