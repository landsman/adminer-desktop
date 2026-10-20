// Every title="" shown as our own tooltip: the WebView's native one waits about a second and
// draws small grey system text that ignores the theme.
//
// The title is lifted off the element only while it is hovered, so the native one never fires,
// and put back on leave — the attribute stays the accessible description, and titles set later
// by adminer or our own scripts are picked up with no list of elements.
(() => {
	// Short, not zero: a pointer just passing over a row of icons should not flash each label.
	const DELAY_MS = 200;
	const GAP_PX = 6;

	let tip = null;
	let owner = null;
	let text = "";
	let lent = false;
	let timer = 0;

	function show() {
		if (!tip) {
			tip = document.createElement("div");
			tip.id = "ad-tooltip";
			tip.setAttribute("role", "tooltip");
		}
		// Inside an open modal <dialog>, or the top layer paints it over the tooltip (toast.js).
		const host = document.querySelector("dialog[open]") || document.body;
		if (tip.parentNode !== host) {
			host.appendChild(tip);
		}
		tip.textContent = text;
		if (typeof tip.showPopover === "function") {
			tip.setAttribute("popover", "manual");
			try {
				tip.showPopover();
			} catch {
				// Already showing.
			}
		}
		tip.hidden = false;

		// Below the element and centred on it; above when there is no room, clamped to the window.
		const box = owner.getBoundingClientRect();
		const width = tip.offsetWidth;
		const height = tip.offsetHeight;
		let top = box.bottom + GAP_PX;
		if (top + height > innerHeight) {
			top = box.top - GAP_PX - height;
		}
		const left = Math.min(
			Math.max(GAP_PX, box.left + (box.width - width) / 2),
			innerWidth - width - GAP_PX,
		);
		tip.style.top = `${Math.max(GAP_PX, top)}px`;
		tip.style.left = `${left}px`;
	}

	function hide() {
		clearTimeout(timer);
		if (tip) {
			tip.hidden = true;
		}
		// A script may have set a new title while it was hovered (copy-field.js says "Copied"); that
		// one wins over the one we took.
		if (owner && !owner.hasAttribute("title")) {
			owner.setAttribute("title", text);
		}
		if (owner && lent) {
			owner.removeAttribute("aria-label");
		}
		owner = null;
		lent = false;
	}

	document.addEventListener("mouseover", (event) => {
		const target = event.target.closest?.("[title]");
		if (!target || target === owner) {
			return;
		}
		hide();
		text = target.getAttribute("title").trim();
		if (!text) {
			return;
		}
		owner = target;
		owner.removeAttribute("title");
		// An icon-only link (the sidebar's actions) is named by its title alone; keep it named.
		lent = !owner.hasAttribute("aria-label") && !owner.textContent.trim();
		if (lent) {
			owner.setAttribute("aria-label", text);
		}
		timer = setTimeout(show, DELAY_MS);
	});

	document.addEventListener("mouseout", (event) => {
		if (owner && !owner.contains(event.relatedTarget)) {
			hide();
		}
	});

	// Whatever the hovered element does next — a click, a key, a scroll — the tooltip is in the way.
	for (const type of ["mousedown", "keydown", "scroll", "blur"]) {
		addEventListener(type, () => owner && hide(), true);
	}
})();
