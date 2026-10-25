/**
 * Drag an edge to set a width: the sidebar's divider (sidebar-resize.js) and a column's grip
 * (table-columns.js). The pointer is captured on the edge, so the drag holds when it outruns it.
 *
 * grab() runs on the press and returns the width the drag starts from; set() gets the width
 * as the pointer moves; done() runs once on release, to keep it.
 *
 * Sorted before both callers, so it is defined by the time they run (see api.js).
 */
window.desktopDragWidth = (edge, { grab, set, done }) => {
	// A drag ends in a click on the edge like any other press, which on the data list's header
	// row is adminer's "select every row" (tableClick); dblclick too, as two quick drags are one.
	for (const bubbling of ["click", "dblclick"]) {
		edge.addEventListener(bubbling, (event) => event.stopPropagation());
	}

	let startX = 0;
	let startWidth = 0;
	edge.addEventListener("pointerdown", (event) => {
		startWidth = grab();
		startX = event.clientX;
		edge.setPointerCapture(event.pointerId);
		// Stop the drag selecting the text the pointer sweeps over.
		document.body.style.userSelect = "none";
		event.preventDefault();
	});
	edge.addEventListener("pointermove", (event) => {
		if (edge.hasPointerCapture(event.pointerId)) {
			set(startWidth + event.clientX - startX);
		}
	});
	// The capture ends on release and on a cancelled drag alike, so the selection always comes back.
	edge.addEventListener("lostpointercapture", () => {
		document.body.style.userSelect = "";
		done();
	});
};
