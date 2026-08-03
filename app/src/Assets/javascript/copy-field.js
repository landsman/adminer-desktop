// Copy button for the copy-field component (src/Assets/copy-field.latte).
//
// Binds every instance on the page by data attribute rather than by id, so a page can carry
// more than one and nothing has to invent unique ids. Not an onclick attribute: the app's CSP
// carries strict-dynamic, which by spec makes the 'unsafe-inline' beside it ignored — and
// -debug strips strict-dynamic, so an inline handler would work while you debug it and do
// nothing for everyone else.
document.addEventListener("DOMContentLoaded", () => {
	for (const field of document.querySelectorAll("[data-copy-field]")) {
		const value = field.querySelector("[data-copy-value]");
		const button = field.querySelector("[data-copy-button]");
		if (!value || !button) {
			continue;
		}

		// Selecting on focus means keyboard and mouse both get the whole thing in one go, which
		// is the fallback when the clipboard write is refused.
		value.addEventListener("focus", () => value.select());

		// The button is icon-only, so its label is the title and the accessible name rather than
		// any text to rewrite.
		const label = button.getAttribute("aria-label") || "";
		const say = (text) => {
			button.title = text;
			button.setAttribute("aria-label", text);
		};
		let restore = 0;
		button.addEventListener("click", async () => {
			value.select();
			try {
				// Available on http://127.0.0.1 too: loopback counts as a secure context.
				await navigator.clipboard.writeText(value.value);
			} catch {
				// Refused (no permission, or an older webview). The text is selected either
				// way, so the user can still take it — say nothing rather than claim success.
				return;
			}
			const copied = button.dataset.copied || label;
			// The toast is what a person actually sees now that the button carries no text —
			// the title only appears on hover. window.adToast is toast.js, same folder.
			say(copied);
			if (typeof window.adToast === "function") {
				window.adToast(copied);
			}
			clearTimeout(restore);
			restore = setTimeout(() => {
				say(label);
			}, 1500);
		});
	}
});
