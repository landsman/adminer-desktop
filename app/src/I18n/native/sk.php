<?php
declare(strict_types=1);

// Native-shell strings, Slovak. IDs mirror en.php (the base); an ID left out here falls back to
// English, so `make i18n-check` lists what is still missing. Adminer and Editor are product names
// and stay untranslated.

return [
	// Menu bar.
	'menu.about' => 'O aplikácii Adminer Desktop',
	'menu.open_logs' => 'Otvoriť logy',
	'menu.quit' => 'Ukončiť Adminer Desktop',
	'menu.edit' => 'Upraviť',
	'menu.undo' => 'Späť',
	'menu.redo' => 'Znova',
	'menu.cut' => 'Vystrihnúť',
	'menu.copy' => 'Kopírovať',
	'menu.paste' => 'Prilepiť',
	'menu.select_all' => 'Vybrať všetko',
	'menu.help' => 'Pomocník',
	'menu.adminer_website' => 'Web Admineru',
	'menu.github' => 'adminer-desktop na GitHube',
	'menu.report_issue' => 'Nahlásiť chybu',

	// About panel credits. %1$@ verzia Admineru, %2$@ verzia FrankenPHP, %3$@ verzia aplikácie.
	'about.credits' => "Adminer %1\$@ — Jakub Vrána a prispievatelia\nApache-2.0 / GPL-2.0 · https://www.adminer.org\n\nBehové prostredie: FrankenPHP %2\$@ (Caddy + PHP)\nMIT · https://frankenphp.dev\n\nadminer-desktop %3\$@ — MIT\nhttps://github.com/landsman/adminer-desktop\n\nTáto aplikácia Adminer nijako neupravuje. Stiahne oficiálne vydanie v pevne danej verzii, overí jeho kontrolný súčet a spustí ho v natívnom okne.",

	// JavaScript alert/confirm/prompt buttons.
	'dialog.ok' => 'OK',
	'dialog.cancel' => 'Zrušiť',

	// Export download UI. %@ je názov uloženého súboru.
	'download.save_title' => 'Uložiť export',
	'download.saved' => 'Uložené %@',
	'download.cancel_confirm' => 'Zrušiť toto sťahovanie?',
	'download.cancel_button' => 'Zrušiť sťahovanie',
	'download.keep' => 'Pokračovať v sťahovaní',
];
