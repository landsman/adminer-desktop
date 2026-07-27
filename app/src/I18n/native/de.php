<?php
declare(strict_types=1);

// Native-shell strings, German. IDs mirror en.php (the base); an ID left out here falls back to
// English, so `make i18n-check` lists what is still missing. Adminer and Editor are product names
// and stay untranslated.

return [
	// Menu bar.
	'menu.about' => 'Über Adminer Desktop',
	'menu.open_logs' => 'Protokolle öffnen',
	'menu.quit' => 'Adminer Desktop beenden',
	'menu.edit' => 'Bearbeiten',
	'menu.undo' => 'Rückgängig',
	'menu.redo' => 'Wiederholen',
	'menu.cut' => 'Ausschneiden',
	'menu.copy' => 'Kopieren',
	'menu.paste' => 'Einfügen',
	'menu.select_all' => 'Alles auswählen',
	'menu.help' => 'Hilfe',
	'menu.adminer_website' => 'Adminer-Website',
	'menu.github' => 'adminer-desktop auf GitHub',
	'menu.report_issue' => 'Fehler melden',

	// About panel credits. %1$@ Adminer-Version, %2$@ FrankenPHP-Version, %3$@ App-Version.
	'about.credits' => "Adminer %1\$@ — von Jakub Vrána und Mitwirkenden\nApache-2.0 / GPL-2.0 · https://www.adminer.org\n\nLaufzeitumgebung: FrankenPHP %2\$@ (Caddy + PHP)\nMIT · https://frankenphp.dev\n\nadminer-desktop %3\$@ — MIT\nhttps://github.com/landsman/adminer-desktop\n\nDiese App verändert Adminer nicht. Sie lädt die offizielle Veröffentlichung in einer festgelegten Version herunter, prüft deren Prüfsumme und führt sie in einem nativen Fenster aus.",

	// JavaScript alert/confirm/prompt buttons.
	'dialog.ok' => 'OK',
	'dialog.cancel' => 'Abbrechen',

	// Export download UI. %@ ist der Name der gespeicherten Datei.
	'download.save_title' => 'Export speichern',
	'download.saved' => '%@ gespeichert',
	'download.cancel_confirm' => 'Diesen Download abbrechen?',
	'download.cancel_button' => 'Download abbrechen',
	'download.keep' => 'Download fortsetzen',
];
