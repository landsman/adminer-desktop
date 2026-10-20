// The sidebar's database and schema pickers lose their "DB:" and "Schema:" in our theme
// (sidebar.css), so each label's text becomes its tooltip instead. Adminer titles the database one
// already; the schema one it does not.
for (const label of document.querySelectorAll("#dbs label:not([title])")) {
	// The text node before the select; the label's textContent would carry every option too.
	const text =
		label.firstChild?.nodeType === Node.TEXT_NODE
			? label.firstChild.textContent.replace(/:\s*$/, "").trim()
			: "";
	if (text) {
		label.title = text;
	}
}
