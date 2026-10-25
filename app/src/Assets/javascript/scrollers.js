/**
 * Overlay scrollbars as macOS draws them while the system is set to overlay ones (a trackpad, a
 * Magic Mouse): a thumb over the content that shows while it scrolls and fades a moment after.
 *
 * WebKit cannot draw one: its scrollbar always takes room, so base.css hides the two panels' bars
 * outright (a transparent one still cut every rule short of the edge) and the inner scrollers'
 * until the pointer is over them. That left the panels with no scrollbar at all, even mid-scroll.
 * So the panels get a thumb of our own, laid over their edge and draggable; an inner scroller is
 * marked .ad-scrolling for that moment, and base.css shows its own thumb on it.
 *
 * While the system draws legacy scrollbars (a mouse, or System Settings set to Always: the
 * launcher marks <html> with ad-legacy-scrollers) the panels' thumbs are ours too, only always on
 * screen and as thick as Mail's: WebKit's own took a column of the panel, and the toolbar inside
 * #content, its hairline and its search, stopped that column short of the window's edge. The
 * inner scrollers keep WebKit's legacy ones. macOS only, as base.css's rules are.
 */

{
	// About as long as macOS keeps the thumb after the last move.
	const LINGER_MS = 800;
	const THICK = 7;
	const INSET = 2;
	// Mail's legacy bar: 9px, centred in the 16px a native track takes.
	const LEGACY_THICK = 9;
	const LEGACY_INSET = 3.5;
	const MIN = 32;
	const timers = new WeakMap();

	// Our design only, which hides WebKit's bars and styles the thumb (base.css); --ad-accent is
	// defined by it alone, as table-nav.js tells it.
	const ours = () =>
		document.body?.classList.contains("os-mac") &&
		getComputedStyle(document.documentElement)
			.getPropertyValue("--ad-accent")
			.trim() !== "";
	const legacy = () =>
		document.documentElement.classList.contains("ad-legacy-scrollers");
	// The thumb's thickness and its inset off the panel's edge, in the mode the system is in.
	const size = () => (legacy() ? [LEGACY_THICK, LEGACY_INSET] : [THICK, INSET]);

	// One thumb per axis for each panel, in <body> so the panel's own clipping cannot hide it.
	const thumbs = new Map();
	const thumbsOf = (panel) => {
		let pair = thumbs.get(panel);
		if (!pair) {
			pair = ["y", "x"].map((axis) => {
				const thumb = document.createElement("div");
				thumb.className = `ad-thumb ad-thumb-${axis}`;
				thumb.setAttribute("aria-hidden", "true");
				document.body.append(thumb);
				drag(thumb, panel, axis);
				// Kept while hovered, so the fade starts again once the pointer leaves; without it a
				// thumb left under the pointer stayed on screen, and clickable, until the next scroll.
				thumb.addEventListener("pointerleave", () => show(panel));
				return thumb;
			});
			thumbs.set(panel, pair);
		}
		return pair;
	};

	// Where the thumb goes: the visible share of the content is its length, how far it scrolled
	// its offset along the track.
	// Under no title bar the toolbar's band tops both panels, and the track starts below it, as
	// Mail's does, legacy bars and overlay ones alike.
	const lead = () => {
		const root = document.documentElement;
		return root.classList.contains("ad-frameless")
			? Number.parseFloat(
					getComputedStyle(root).getPropertyValue("--ad-titlebar-h"),
				) || 0
			: 0;
	};

	const place = (panel) => {
		const rect = panel.getBoundingClientRect();
		const [ty, tx] = thumbsOf(panel);
		// A hidden sidebar's bar goes with it, at once: in <body>, it stayed behind over the content.
		if (panel.matches(".ad-sidebar-hidden #menu")) {
			ty.hidden = true;
			tx.hidden = true;
			return;
		}
		const [thick, inset] = size();
		const axes = [
			[
				ty,
				panel.clientHeight,
				panel.scrollHeight,
				panel.scrollTop,
				rect.top + lead(),
				rect.height - lead(),
			],
			[
				tx,
				panel.clientWidth,
				panel.scrollWidth,
				panel.scrollLeft,
				rect.left,
				rect.width,
			],
		];
		for (const [thumb, view, full, at, start, track] of axes) {
			if (full <= view + 1) {
				thumb.hidden = true;
				continue;
			}
			thumb.hidden = false;
			const length = Math.max(MIN, ((track - 2 * inset) * view) / full);
			const offset =
				start + inset + ((track - 2 * inset - length) * at) / (full - view);
			if (thumb === ty) {
				Object.assign(thumb.style, {
					top: `${offset}px`,
					height: `${length}px`,
					left: `${rect.right - thick - inset}px`,
				});
			} else {
				Object.assign(thumb.style, {
					left: `${offset}px`,
					width: `${length}px`,
					top: `${rect.bottom - thick - inset}px`,
				});
			}
		}
	};

	const show = (panel) => {
		place(panel);
		for (const thumb of thumbsOf(panel)) {
			thumb.classList.add("ad-shown");
		}
		// A legacy bar stays, as the system's does.
		if (legacy()) {
			return;
		}
		clearTimeout(timers.get(panel));
		timers.set(
			panel,
			setTimeout(() => {
				for (const thumb of thumbsOf(panel)) {
					if (!thumb.matches(":hover, .ad-dragging")) {
						thumb.classList.remove("ad-shown");
					}
				}
			}, LINGER_MS),
		);
	};

	// A thumb can be grabbed and dragged, as a native one can; the panel scrolls the distance the
	// pointer moved, scaled from the track to the content.
	function drag(thumb, panel, axis) {
		thumb.addEventListener("pointerdown", (event) => {
			event.preventDefault();
			thumb.setPointerCapture(event.pointerId);
			thumb.classList.add("ad-dragging");
			const y = axis === "y";
			const from = y ? event.clientY : event.clientX;
			const at = y ? panel.scrollTop : panel.scrollLeft;
			const view = y ? panel.clientHeight : panel.clientWidth;
			const full = y ? panel.scrollHeight : panel.scrollWidth;
			const length = y ? thumb.offsetHeight : thumb.offsetWidth;
			// The track the thumb runs along, as place() measured it.
			const track = y
				? panel.getBoundingClientRect().height - lead()
				: panel.getBoundingClientRect().width;
			const scale = (full - view) / (track - 2 * size()[1] - length);
			const move = (e) => {
				const to = at + ((y ? e.clientY : e.clientX) - from) * scale;
				panel[y ? "scrollTop" : "scrollLeft"] = to;
			};
			const end = () => {
				thumb.removeEventListener("pointermove", move);
				thumb.classList.remove("ad-dragging");
				show(panel);
			};
			thumb.addEventListener("pointermove", move);
			thumb.addEventListener("lostpointercapture", end, { once: true });
		});
	}

	const PANELS = "#menu, #content";

	addEventListener(
		"scroll",
		(event) => {
			const scroller =
				event.target === document ? document.scrollingElement : event.target;
			if (!(scroller instanceof Element) || !ours()) {
				return;
			}
			if (scroller.matches(PANELS)) {
				show(scroller);
				return;
			}
			if (legacy()) {
				return;
			}
			scroller.classList.add("ad-scrolling");
			clearTimeout(timers.get(scroller));
			timers.set(
				scroller,
				setTimeout(() => scroller.classList.remove("ad-scrolling"), LINGER_MS),
			);
		},
		// Scroll does not bubble; captured, one listener hears every scroller on the page.
		true,
	);

	// A legacy bar is there before anything scrolls, and follows the panel's content as it grows or
	// shrinks (a refresh swapping the rows), as no scroll event says.
	const panels = () => document.querySelectorAll(PANELS);
	if (ours()) {
		const sized = new ResizeObserver((entries) => {
			if (!legacy()) {
				return;
			}
			for (const panel of new Set(
				entries.map((entry) => entry.target.closest(PANELS)),
			)) {
				show(panel);
			}
		});
		for (const panel of panels()) {
			sized.observe(panel);
			for (const child of panel.children) {
				sized.observe(child);
			}
		}
		// The launcher flips the mode on an open page (a mouse plugged in or out, the setting
		// changed): the bars come up at once, or are let fade as overlay ones do. Only on that
		// flip: <html> changes its classes on every focus and scroll to the top as well.
		let was = legacy();
		new MutationObserver(() => {
			if (legacy() === was) {
				return;
			}
			was = legacy();
			for (const panel of panels()) {
				show(panel);
			}
		}).observe(document.documentElement, {
			attributes: true,
			attributeFilter: ["class"],
		});
	}

	// The sidebar's toggle (window-chrome.js) sets a class and slides the sidebar: no scroll and no
	// resize says so. Hidden, place() takes its bar away; shown, the bar comes back once it is in.
	new MutationObserver(() => {
		for (const panel of thumbs.keys()) {
			place(panel);
		}
	}).observe(document.body, { attributes: true, attributeFilter: ["class"] });
	document.querySelector("#foot")?.addEventListener("transitionend", () => {
		const menu = document.querySelector("#menu");
		if (
			menu &&
			thumbs.has(menu) &&
			thumbs.get(menu)[0].classList.contains("ad-shown")
		) {
			place(menu);
		}
	});

	// A resize moves the panels' edges, and the thumbs with them, while they are on screen.
	addEventListener("resize", () => {
		for (const [panel, pair] of thumbs) {
			if (pair.some((thumb) => thumb.classList.contains("ad-shown"))) {
				place(panel);
			}
		}
	});
}
