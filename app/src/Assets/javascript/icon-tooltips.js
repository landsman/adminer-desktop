/**
 * The tooltip on the links the theme draws as icons: each row's pencil (tables.css) and the page's
 * actions in the toolbar (window.css). Its text is the link's own word, adminer's translation,
 * which the icon only hides. Set as the pointer comes over it, in the capture phase so tooltip.js
 * finds it on the same event, which also covers links that arrive after load.
 */

document.addEventListener(
	"mouseover",
	(event) => {
		const link = /** @type {Element} */ (event.target).closest?.(
			"#table td > a.edit:not([title]), .ad-toolbar-actions a:not([title])",
		);
		if (link) {
			link.title = link.textContent.trim();
		}
	},
	true,
);
