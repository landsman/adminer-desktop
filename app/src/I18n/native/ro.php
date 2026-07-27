<?php
declare(strict_types=1);

// Native-shell strings, Romanian. IDs mirror en.php (the base); an ID left out here falls back to
// English, so `make i18n-check` lists what is still missing. Adminer and Editor are product names
// and stay untranslated.

return [
	// Menu bar.
	'menu.about' => 'Despre Adminer Desktop',
	'menu.open_logs' => 'Deschide jurnalele',
	'menu.quit' => 'Închide Adminer Desktop',
	'menu.edit' => 'Editare',
	'menu.undo' => 'Anulează',
	'menu.redo' => 'Refă',
	'menu.cut' => 'Decupează',
	'menu.copy' => 'Copiază',
	'menu.paste' => 'Lipește',
	'menu.select_all' => 'Selectează tot',
	'menu.help' => 'Ajutor',
	'menu.adminer_website' => 'Site-ul Adminer',
	'menu.github' => 'adminer-desktop pe GitHub',
	'menu.report_issue' => 'Raportează o problemă',

	// About panel credits. %1$@ versiunea Adminer, %2$@ versiunea FrankenPHP, %3$@ versiunea aplicației.
	'about.credits' => "Adminer %1\$@ — de Jakub Vrána și colaboratori\nApache-2.0 / GPL-2.0 · https://www.adminer.org\n\nMediu de rulare: FrankenPHP %2\$@ (Caddy + PHP)\nMIT · https://frankenphp.dev\n\nadminer-desktop %3\$@ — MIT\nhttps://github.com/landsman/adminer-desktop\n\nAceastă aplicație nu modifică Adminer. Descarcă versiunea oficială fixată, îi verifică suma de control și o rulează într-o fereastră nativă.",

	// JavaScript alert/confirm/prompt buttons.
	'dialog.ok' => 'OK',
	'dialog.cancel' => 'Anulează',

	// Export download UI. %@ este numele fișierului salvat.
	'download.save_title' => 'Salvează exportul',
	'download.saved' => 'Salvat %@',
	'download.cancel_confirm' => 'Anulați această descărcare?',
	'download.cancel_button' => 'Anulează descărcarea',
	'download.keep' => 'Continuă descărcarea',
];
