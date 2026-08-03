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
	function container() {
		let node = document.getElementById("ad-toasts");
		if (!node) {
			node = document.createElement("div");
			node.id = "ad-toasts";
			// Announced but not focus-stealing: the message is a confirmation, and moving focus
			// to it would interrupt whatever the user does next.
			node.setAttribute("role", "status");
			node.setAttribute("aria-live", "polite");
			document.body.appendChild(node);
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
		container().appendChild(toast);

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
