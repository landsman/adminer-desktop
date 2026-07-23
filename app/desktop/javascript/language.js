/**
 * Move Adminer's language switch into the settings dialog.
 *
 * Adminer draws a language <select> loose in the top corner of every page; a desktop app
 * keeps a preference like that with the others. This relocates the select into the theme
 * panel's slot (Desktop\Theme renders #desktop-lang-slot).
 *
 * Its own onchange submits the surrounding form, but in the dialog that is our settings
 * form, which redirects in handlePost before Adminer ever reads $_POST["lang"] — the switch
 * would be lost. So the change is rewired to a standalone POST of just lang + token, which
 * is exactly what Adminer's own form sends and all its language handler needs.
 */

const lang = document.querySelector("#lang");
const slot = document.querySelector("#desktop-lang-slot");
const select = lang?.querySelector('select[name="lang"]');
const token = lang?.querySelector('input[name="token"]');

if (slot && select && token) {
	const tokenValue = token.value;
	select.onchange = () => {
		const form = document.createElement("form");
		form.method = "post";
		form.action = "";
		const langField = document.createElement("input");
		langField.type = "hidden";
		langField.name = "lang";
		langField.value = select.value;
		const tokenField = document.createElement("input");
		tokenField.type = "hidden";
		tokenField.name = "token";
		tokenField.value = tokenValue;
		form.append(langField, tokenField);
		document.body.append(form);
		form.submit();
	};
	slot.append(select);
	lang.remove();
}
