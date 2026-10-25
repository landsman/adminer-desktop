/**
 * A dialog the page opens on load (a data-open-dialog marker: a new connection's naming offer, a
 * failed login's form) is there from the first frame.
 *
 * Opened by modal.js, which is deferred, it came after the first paint: the page landed lit, the
 * view transition slid the sidebar in over it (base.css), and then the backdrop dimmed it all
 * again. So it opens here, as the parser adds its marker — a mutation observer's callback runs
 * before the browser paints — without its fade, and the page skips the transition into it: the
 * dialog is what there is to look at. modal.js finds it open and only wires it up.
 */

{
	let transition = null;
	let opened = false;

	addEventListener("pagereveal", (event) => {
		transition = event.viewTransition;
		if (opened) {
			transition?.skipTransition();
		}
	});

	const open = (marker) => {
		const dialog = document.getElementById(marker.dataset.openDialog);
		if (!dialog || dialog.open) {
			return;
		}
		// No fade in: the frame before this one is the previous page. Two frames on, once the
		// open state has been drawn, closing fades out as any dialog does.
		dialog.classList.add("ad-modal-instant");
		dialog.showModal();
		requestAnimationFrame(() =>
			requestAnimationFrame(() => dialog.classList.remove("ad-modal-instant")),
		);
		opened = true;
		transition?.skipTransition();
	};

	const observer = new MutationObserver((records) => {
		for (const record of records) {
			// Text nodes too, which have no matches(): hence the ?.
			for (const node of /** @type {NodeListOf<Element>} */ (
				record.addedNodes
			)) {
				if (node.matches?.("[data-open-dialog]")) {
					open(node);
				}
			}
		}
	});
	observer.observe(document, { childList: true, subtree: true });
	addEventListener("DOMContentLoaded", () => observer.disconnect());
}
