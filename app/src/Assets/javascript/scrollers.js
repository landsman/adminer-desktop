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
 * Not while the system draws legacy scrollbars (a mouse, or System Settings set to Always): the
 * launcher marks <html> with ad-legacy-scrollers then, and WebKit's own are left on screen.
 * macOS only, as base.css's rules are.
 */

{
	// About as long as macOS keeps the thumb after the last move.
	const LINGER_MS = 800;
	const THICK = 7;
	const INSET = 2;
	const MIN = 32;
	const timers = new WeakMap();

	const overlay = () =>
		document.body?.classList.contains("os-mac") &&
		!document.documentElement.classList.contains("ad-legacy-scrollers");

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
				return thumb;
			});
			thumbs.set(panel, pair);
		}
		return pair;
	};

	// Where the thumb goes: the visible share of the content is its length, how far it scrolled
	// its offset along the track.
	const place = (panel) => {
		const rect = panel.getBoundingClientRect();
		const [ty, tx] = thumbsOf(panel);
		const axes = [
			[
				ty,
				panel.clientHeight,
				panel.scrollHeight,
				panel.scrollTop,
				rect.top,
				rect.height,
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
			const length = Math.max(MIN, ((track - 2 * INSET) * view) / full);
			const offset =
				start + INSET + ((track - 2 * INSET - length) * at) / (full - view);
			if (thumb === ty) {
				Object.assign(thumb.style, {
					top: `${offset}px`,
					height: `${length}px`,
					left: `${rect.right - THICK - INSET}px`,
				});
			} else {
				Object.assign(thumb.style, {
					left: `${offset}px`,
					width: `${length}px`,
					top: `${rect.bottom - THICK - INSET}px`,
				});
			}
		}
	};

	const show = (panel) => {
		place(panel);
		for (const thumb of thumbsOf(panel)) {
			thumb.classList.add("ad-shown");
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
			const scale = (full - view) / (view - 2 * INSET - length);
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
			if (!(scroller instanceof Element) || !overlay()) {
				return;
			}
			if (scroller.matches(PANELS)) {
				show(scroller);
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

	// A resize moves the panels' edges, and the thumbs with them, while they are on screen.
	addEventListener("resize", () => {
		for (const [panel, pair] of thumbs) {
			if (pair.some((thumb) => thumb.classList.contains("ad-shown"))) {
				place(panel);
			}
		}
	});
}
