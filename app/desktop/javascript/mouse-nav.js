/**
 * Back and forward on the mouse's side buttons.
 *
 * The WebViews do not wire the side buttons on a mouse (e.g. a Logitech MX) to history
 * navigation, so pressing Back did nothing. button 3 is Back, button 4 is Forward.
 *
 * @param {MouseEvent} e
 */
function mouseNav(e) {
	if (e.button === 3) {
		e.preventDefault();
		history.back();
	} else if (e.button === 4) {
		e.preventDefault();
		history.forward();
	}
}

addEventListener("mouseup", mouseNav);
