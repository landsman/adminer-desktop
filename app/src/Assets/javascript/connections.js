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

const login = document.querySelector('[name="auth[driver]"]')?.form;

// A server that never answers keeps the login waiting until the connection times out, minutes on
// some networks. The wait shows in the modal (busy.js), and Cancel, or closing the modal, stops it:
// window.stop() drops the request, and the server, no longer holding the session for it
// (patches/adminer-connect-without-session-lock.patch), serves the next one straight away.
if (login) {
	login.setAttribute("data-busy-here", "");
	const cancel = () => {
		if (login.classList.contains("ad-waiting")) {
			window.stop();
			login.classList.remove("ad-waiting");
		}
	};
	const button = login.querySelector(".ad-connect-cancel");
	button?.addEventListener("click", cancel);
	// The focus follows Login to Cancel, so Enter stops the wait rather than sending it twice.
	login.addEventListener("submit", () => button?.focus());
	document
		.querySelector("#ad-new-connection-dialog")
		?.addEventListener("close", cancel);
}

// A login that failed comes back as this page with the modal open again on its fields, and
// adminer's error at the top of the page, behind it: moved into the modal, where it is read beside
// them. The password comes back too, which adminer never prints: kept in this tab for the one
// request only, so a typo in the host does not mean typing it again.
const typed = sessionStorage.getItem("ad-login-password");
sessionStorage.removeItem("ad-login-password");
if (login) {
	const password = login.querySelector('[name="auth[password]"]');
	login.addEventListener("submit", () =>
		sessionStorage.setItem("ad-login-password", password?.value ?? ""),
	);
	const error = document.querySelector("#content > .error");
	if (
		error &&
		document.querySelector('[data-open-dialog="ad-new-connection-dialog"]')
	) {
		document
			.querySelector("#ad-new-connection-dialog .ad-modal-body")
			?.prepend(error);
		if (password && typed) {
			password.value = typed;
		}
	}
}
