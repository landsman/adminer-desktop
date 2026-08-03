// A brief message that confirms something happened and then gets out of the way.
//
// Exposed as window.adToast so any of our scripts can call it without a module system — the
// page loads plain files, and the alternative is every caller reimplementing the same div.
//
// Deliberately not an alert(): these confirm an action the user just took, so they must not
// need dismissing. Anything the user has to acknowledge is an error, and errors belong in the
// page rather than in something that vanishes.
(() => {
	const VISIBLE_MS = 2000;

	// One container, created on first use and reused: appending to body per toast would stack
	// them at different positions as the earlier ones are removed.
	//
	// It moves into whatever modal <dialog> is open, and that is the whole reason toasts were
	// invisible from the settings dialog. A modal dialog renders in the *top layer*, which sits
	// above the entire page stacking context — z-index does not reach across it, so the toast
	// was painting behind the dialog no matter how large its z-index was. Being a descendant of
	// the dialog puts it in the top layer too. `position: fixed` still positions it against the
	// viewport, so it lands in the same corner either way.
	function container() {
		const host = document.querySelector("dialog[open]") || document.body;
		let node = document.getElementById("ad-toasts");
		if (!node) {
			node = document.createElement("div");
			node.id = "ad-toasts";
			// Announced but not focus-stealing: the message is a confirmation, and moving focus
			// to it would interrupt whatever the user does next.
			node.setAttribute("role", "status");
			node.setAttribute("aria-live", "polite");
		}
		// appendChild moves it when it is already somewhere else — the dialog it was in may since
		// have closed, taking the container off the page with it.
		if (node.parentNode !== host) {
			host.appendChild(node);
		}
		return node;
	}

	window.adToast = (message) => {
		if (!message) {
			return;
		}
		const toast = document.createElement("div");
		toast.className = "ad-toast";
		// textContent, not innerHTML: callers pass translated strings, and none of them should
		// be able to introduce markup here.
		toast.textContent = message;
		const host = container();
		host.appendChild(toast);
		// The top layer, on top of everything including a modal dialog, and above other top-layer
		// elements because the last one promoted paints last. Belt and braces with the move in
		// container(): a popover is unconditional, but WebKit only has it from Safari 17, and the
		// move needs no feature at all. Manual, so nothing light-dismisses it.
		if (typeof host.showPopover === "function") {
			host.setAttribute("popover", "manual");
			try {
				host.showPopover();
			} catch {
				// Already showing. Nothing to do — it is in the top layer either way.
			}
		}

		setTimeout(() => {
			toast.classList.add("ad-toast-leaving");
			// Remove after the fade rather than on a timer of its own, so a reduced-motion user
			// (no transition, so no event) is handled by the fallback timeout below.
			let removed = false;
			const drop = () => {
				if (!removed) {
					removed = true;
					toast.remove();
				}
			};
			toast.addEventListener("transitionend", drop);
			setTimeout(drop, 400);
		}, VISIBLE_MS);
	};
})();
