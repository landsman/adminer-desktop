<?php
declare(strict_types=1);

// Native-shell strings, Polish. IDs mirror en.php (the base); an ID left out here falls back to
// English, so `make i18n-check` lists what is still missing. Adminer and Editor are product names
// and stay untranslated.

return [
	// Menu bar.
	'menu.about' => 'O aplikacji Adminer Desktop',
	'menu.open_logs' => 'Otwórz logi',
	'menu.quit' => 'Zakończ Adminer Desktop',
	'menu.edit' => 'Edycja',
	'menu.undo' => 'Cofnij',
	'menu.redo' => 'Ponów',
	'menu.cut' => 'Wytnij',
	'menu.copy' => 'Kopiuj',
	'menu.paste' => 'Wklej',
	'menu.select_all' => 'Zaznacz wszystko',
	'menu.help' => 'Pomoc',
	'menu.adminer_website' => 'Strona Adminera',
	'menu.github' => 'adminer-desktop na GitHubie',
	'menu.report_issue' => 'Zgłoś błąd',

	// About panel credits. %1$@ wersja Adminera, %2$@ wersja FrankenPHP, %3$@ wersja aplikacji.
	'about.credits' => "Adminer %1\$@ — Jakub Vrána i współtwórcy\nApache-2.0 / GPL-2.0 · https://www.adminer.org\n\nŚrodowisko uruchomieniowe: FrankenPHP %2\$@ (Caddy + PHP)\nMIT · https://frankenphp.dev\n\nadminer-desktop %3\$@ — MIT\nhttps://github.com/landsman/adminer-desktop\n\nTa aplikacja nie modyfikuje Adminera. Pobiera oficjalne wydanie w ustalonej wersji, weryfikuje jego sumę kontrolną i uruchamia je w natywnym oknie.",

	// JavaScript alert/confirm/prompt buttons.
	'dialog.ok' => 'OK',
	'dialog.cancel' => 'Anuluj',

	// Export download UI. %@ to nazwa zapisanego pliku.
	'download.save_title' => 'Zapisz eksport',
	'download.saved' => 'Zapisano %@',
	'download.cancel_confirm' => 'Anulować to pobieranie?',
	'download.cancel_button' => 'Anuluj pobieranie',
	'download.keep' => 'Kontynuuj pobieranie',
];
