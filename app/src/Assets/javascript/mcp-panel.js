// Click the registration command to select all of it, so it can be copied without dragging
// across a path that is wider than the field.
//
// A file bound by id rather than an onclick attribute, because the page's CSP carries
// strict-dynamic — which by spec makes the 'unsafe-inline' beside it ignored, so an inline
// handler never runs. It would have looked fine in testing: -debug strips strict-dynamic
// (AdminerDesktop::csp), so the attribute works while you are debugging and silently does
// nothing for everyone else.
document.addEventListener("DOMContentLoaded", () => {
	const field = document.getElementById("desktop-mcp-command");
	if (field) {
		field.addEventListener("focus", () => field.select());
		field.addEventListener("click", () => field.select());
	}
});
