/**
 * The saved-connection cards on the login page (src/Connections/). Opening and closing their
 * dialogs is modal.js; what is left is particular to these.
 */

// Picking an environment picks its colour too — the option carries it — and the swatch stays the
// user's to change afterwards.
for (const select of document.querySelectorAll(
	'.ad-connection-dialog select[name="env"]',
)) {
	select.addEventListener("change", () => {
		const color = select.selectedOptions[0]?.dataset.color ?? "";
		const swatch = select.form.querySelector(
			`input[name="color"][value="${color}"]`,
		);
		if (swatch) {
			swatch.checked = true;
		}
	});
}

// "Permanent login" on by default: in a desktop app remembering the login is the point, and
// unticked the connection vanishes from the list with the session. adminer only ticks it when
// some permanent login already exists.
const permanent = document.querySelector('input[name="auth[permanent]"]');
if (permanent) {
	permanent.checked = true;
}

// A login waits on the database server for as long as it takes to answer or to give up, over a
// minute for a host that never answers, so it shows the activity indicator (busy.js) meanwhile.
const login = document.querySelector('[name="auth[driver]"]')?.form;
login?.setAttribute("data-busy", "");
