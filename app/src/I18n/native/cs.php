<?php
declare(strict_types=1);

// Native-shell strings, Czech. IDs mirror en.php (the base); an ID left out here falls back to
// English, so `make i18n-check` lists what is still missing. Adminer and Editor are product names
// and stay untranslated.

return [
	// Menu bar.
	'menu.about' => 'O aplikaci Adminer Desktop',
	'menu.open_logs' => 'Otevřít logy',
	'menu.quit' => 'Ukončit Adminer Desktop',
	'menu.edit' => 'Úpravy',
	'menu.undo' => 'Zpět',
	'menu.redo' => 'Znovu',
	'menu.cut' => 'Vyjmout',
	'menu.copy' => 'Kopírovat',
	'menu.paste' => 'Vložit',
	'menu.select_all' => 'Vybrat vše',
	'menu.help' => 'Nápověda',
	'menu.adminer_website' => 'Web Admineru',
	'menu.github' => 'adminer-desktop na GitHubu',
	'menu.report_issue' => 'Nahlásit chybu',

	// About panel credits. %1$@ verze Admineru, %2$@ verze FrankenPHP, %3$@ verze aplikace.
	'about.credits' => "Adminer %1\$@ — Jakub Vrána a přispěvatelé\nApache-2.0 / GPL-2.0 · https://www.adminer.org\n\nBěhové prostředí: FrankenPHP %2\$@ (Caddy + PHP)\nMIT · https://frankenphp.dev\n\nadminer-desktop %3\$@ — MIT\nhttps://github.com/landsman/adminer-desktop\n\nTato aplikace Adminer nijak neupravuje. Stáhne oficiální vydání v pevně dané verzi, ověří jeho kontrolní součet a spustí ho v nativním okně.",

	// JavaScript alert/confirm/prompt buttons.
	'dialog.ok' => 'OK',
	'dialog.cancel' => 'Zrušit',

	// Export download UI. %@ je název uloženého souboru.
	'download.save_title' => 'Uložit export',
	'download.saved' => 'Uloženo %@',
	'download.cancel_confirm' => 'Zrušit toto stahování?',
	'download.cancel_button' => 'Zrušit stahování',
	'download.keep' => 'Pokračovat ve stahování',
];
