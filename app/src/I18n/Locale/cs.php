<?php
declare(strict_types=1);

// Native-shell strings, Czech. Keys mirror en.php (the base); a key left out here falls back to
// English, so `make i18n-check` lists what is still missing. Adminer and Editor are product names
// and stay untranslated.

return [
	// Menu bar.
	'About Adminer Desktop' => 'O aplikaci Adminer Desktop',
	'Open Logs' => 'Otevřít logy',
	'Quit Adminer Desktop' => 'Ukončit Adminer Desktop',
	'Edit' => 'Úpravy',
	'Undo' => 'Zpět',
	'Redo' => 'Znovu',
	'Cut' => 'Vyjmout',
	'Copy' => 'Kopírovat',
	'Paste' => 'Vložit',
	'Select All' => 'Vybrat vše',
	'Help' => 'Nápověda',
	'Adminer Website' => 'Web Admineru',
	'adminer-desktop on GitHub' => 'adminer-desktop na GitHubu',
	'Report an Issue' => 'Nahlásit chybu',

	// About panel credits. %1$@ verze Admineru, %2$@ verze FrankenPHP, %3$@ verze aplikace.
	'credits.format' => "Adminer %1\$@ — Jakub Vrána a přispěvatelé\nApache-2.0 / GPL-2.0 · https://www.adminer.org\n\nBěhové prostředí: FrankenPHP %2\$@ (Caddy + PHP)\nMIT · https://frankenphp.dev\n\nadminer-desktop %3\$@ — MIT\nhttps://github.com/landsman/adminer-desktop\n\nTato aplikace Adminer nijak neupravuje. Stáhne oficiální vydání v pevně dané verzi, ověří jeho kontrolní součet a spustí ho v nativním okně.",

	// JavaScript alert/confirm/prompt buttons.
	'OK' => 'OK',
	'Cancel' => 'Zrušit',

	// Export download UI. %@ je název uloženého souboru.
	'Save Export' => 'Uložit export',
	'Saved %@' => 'Uloženo %@',
	'Cancel this download?' => 'Zrušit toto stahování?',
	'Cancel Download' => 'Zrušit stahování',
	'Keep Downloading' => 'Pokračovat ve stahování',
];
