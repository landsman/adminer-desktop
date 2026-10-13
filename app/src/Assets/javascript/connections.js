/**
 * The saved-connection cards on the login page (src/Connections/connections.latte).
 *
 * The pencil on a card opens its edit dialog — printed in the sidebar, outside adminer's login
 * form, and named by the button's data-dialog. "New connection" unfolds adminer's own form,
 * which connections.css hides while #ad-connections lacks .ad-adding.
 */

// closedby="any" closes an edit dialog on a click outside it. Where the webview does not know the
// attribute yet, that click is handled here: one on the backdrop lands on the dialog itself.
if (!("closedBy" in HTMLDialogElement.prototype)) {
	for (const dialog of document.querySelectorAll(".ad-connection-dialog")) {
		dialog.addEventListener("click", (event) => {
			if (event.target === dialog) {
				dialog.close();
			}
		});
	}
}

for (const button of document.querySelectorAll(".ad-card-edit[data-dialog]")) {
	button.onclick = () =>
		document.getElementById(button.dataset.dialog)?.showModal();
}

const add = document.querySelector("#ad-new-connection");
if (add) {
	add.onclick = () => {
		const open = document
			.querySelector("#ad-connections")
			.classList.toggle("ad-adding");
		add.setAttribute("aria-expanded", String(open));
		if (open) {
			document.querySelector("#username")?.focus();
		}
	};
}
