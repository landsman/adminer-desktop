/**
 * Drag the divider between the sidebar and the content to set the sidebar's width, and keep
 * that width across cold starts (issue #11, on the persistent store of issue #10).
 *
 * The width rides on the --ad-sidebar-dragged custom property, which becomes the
 * --ad-sidebar-width the islands layout's #foot reads (theme/designs/adminer-desktop/tokens.css).
 * This inserts a grab handle between the two panels, updates the property live while dragging,
 * and posts the final width to the resize action (src/Api/ResizePreference.php), which stores
 * it in the app's durable config. head() reads it back and emits it before paint, so the next
 * launch opens at the same width with no jump.
 *
 * Only the adminer-desktop theme lays the panels out as side-by-side flex columns, so the
 * handle is added only when that layout is in effect; every other design is left untouched.
 */

const content = document.querySelector("#content");
const foot = document.querySelector("#foot");

// The islands layout makes <body> a flex row; without it there are no side-by-side panels
// to resize (a plain adminer design, or the login page before the sidebar exists).
if (content && foot && getComputedStyle(document.body).display === "flex") {
	const root = document.documentElement;
	// Keep in step with the clamp in src/Api/ResizePreference.php.
	const MIN = 180;
	const MAX = 640;
	const STEP = 16;

	const clamp = (px) => Math.max(MIN, Math.min(MAX, px));
	const apply = (px) => {
		root.style.setProperty("--ad-sidebar-dragged", `${clamp(px)}px`);
		handle.setAttribute("aria-valuenow", String(clamp(Math.round(px))));
	};
	const width = () => foot.getBoundingClientRect().width;

	// Fire-and-forget: nothing waits on the answer, and a beacon survives the page being
	// torn down by the very next navigation.
	const persist = () => {
		navigator.sendBeacon(
			window.adminerDesktop.api.resize,
			new URLSearchParams({
				what: "sidebar",
				width: String(clamp(Math.round(width()))),
			}),
		);
	};

	const handle = document.createElement("div");
	handle.id = "ad-sidebar-resizer";
	handle.tabIndex = 0;
	handle.setAttribute("role", "separator");
	handle.setAttribute("aria-orientation", "vertical");
	handle.setAttribute("aria-label", "Resize sidebar");
	// A separator that takes the focus is a control, and says where it stands and how far it
	// goes (WAI-ARIA); apply() keeps the value current.
	handle.setAttribute("aria-valuemin", String(MIN));
	handle.setAttribute("aria-valuemax", String(MAX));
	handle.setAttribute("aria-valuenow", String(clamp(Math.round(width()))));
	content.before(handle);

	window.adminerDesktop.dragWidth(handle, {
		grab: width,
		set: apply,
		done: persist,
	});

	// Keyboard: the WAI-ARIA splitter pattern — arrows nudge the width, and it saves once on
	// release rather than on every repeat.
	handle.addEventListener("keydown", (e) => {
		const dir = e.key === "ArrowLeft" ? -1 : e.key === "ArrowRight" ? 1 : 0;
		if (dir) {
			apply(width() + dir * STEP);
			e.preventDefault();
		}
	});
	handle.addEventListener("keyup", (e) => {
		if (e.key === "ArrowLeft" || e.key === "ArrowRight") {
			persist();
		}
	});
}
