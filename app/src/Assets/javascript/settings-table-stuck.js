/**
 * Marks a table in the settings dialog whose header is stuck to the top of the dialog's body, for
 * tables.css to square its corners: rows scrolling under a rounded header show in those corners.
 * CSS cannot tell: the scroll-state query is not in WebKit.
 */

(() => {
	const body = document.querySelector("#desktop-settings .ad-modal-body");
	if (!body) {
		return;
	}
	const mark = () => {
		for (const table of body.querySelectorAll("table")) {
			// Stuck is the header no longer sitting where its table starts. A hidden tab's measure
			// the same, so that is not stuck, but it needs marking again once it is shown.
			table.classList.toggle(
				"ad-stuck",
				table.tHead?.getBoundingClientRect().top >
					table.getBoundingClientRect().top,
			);
		}
	};
	body.addEventListener("scroll", mark, { passive: true });
	document.querySelector("#desktop-tabs")?.addEventListener("change", mark);
})();
