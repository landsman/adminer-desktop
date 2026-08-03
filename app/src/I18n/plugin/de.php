<?php
declare(strict_types=1);

// Plugin UI strings, German. IDs mirror en.php (the base); an ID left out here falls back to
// English, so `make i18n-check` lists what is still missing.

return [
	// The plugin's own description, shown in Adminer's plugin list (AdminerDesktop::description()).
	'plugin.description' => 'Passt die Standardwerte von Adminer an den Desktop-Betrieb an.',

	// Settings dialog shell.
	'settings.title' => 'Einstellungen',
	'settings.tab_theme' => 'Darstellung',
	'settings.tab_plugins' => 'Plugins',
	'settings.save' => 'Speichern',
	'settings.close' => 'Schließen',
	'settings.unsaved' => 'Ungespeicherte Änderungen: {n}. Trotzdem schließen?',
	'settings.reset' => 'Auf Standard zurücksetzen',
	'settings.reset_confirm' => 'Alle Einstellungen auf den Standard zurücksetzen? Design, Plugins und die von Ihnen angepassten Breiten gehen verloren. Adminers eigene Sprache und gespeicherte Server bleiben erhalten.',

	// Data list pager (src/Assets/javascript/table-pager.js): "1-20 of 50", the rows on screen
	// out of the rows there are.
	'pager.of' => 'von',

	// Theme panel.
	'theme.appearance' => 'Erscheinungsbild',
	'theme.appearance_hint' => 'Adminer Desktop folgt dem hellen und dunklen Modus des Systems, oder Sie legen ihn fest auf Hell oder Dunkel. Jeder Modus verwendet das unten gewählte Design.',
	'theme.appearance_auto' => 'Mit System synchronisieren',
	'theme.light' => 'Hell',
	'theme.dark' => 'Dunkel',
	'theme.language' => 'Sprache',
	'theme.density' => 'Zeilendichte',
	'theme.density_compact' => 'Kompakt',
	'theme.density_cozy' => 'Mittel',
	'theme.density_comfortable' => 'Luftig',
	'theme.scaling' => 'Skalierung',
	'theme.design' => 'Design',
	'theme.preview' => 'Vorschau',
	// Product name; the same on both sides, but carried here so coverage stays complete.
	'theme.builtin_design' => 'Adminer Desktop',

	// Plugins panel.
	'plugins.col_name' => 'Plugin',
	'plugins.col_desc' => 'Funktion',

	// Whole-page import dropzone.
	'import.drop_hint' => 'SQL-Datei zum Importieren hier ablegen',

	// Letting an agent query the open database (Mcp\Panel, mcp-panel.latte).
	'settings.tab_mcp' => 'KI-Zugriff',
	'mcp.enable' => 'Einem KI-Agenten erlauben, diese Datenbank abzufragen',
	'mcp.description' => 'Ein unten registrierter Agent kann die Datenbank lesen, in der dieses Fenster angemeldet ist — die Struktur und Abfragen, um die Sie ihn bitten. Schreiben kann er nicht: Jede Abfrage läuft in einer Transaktion, die zurückgerollt wird. Der Zugriff besteht nur, solange dieses Fenster geöffnet und angemeldet ist.',
	'mcp.status_off' => 'Aus. Nichts erreicht die Datenbank.',
	'mcp.status_waiting' => 'An, aber noch nicht erreichbar — melden Sie sich an einer Datenbank an, dann kann der Agent sie abfragen.',
	'mcp.status_ready' => 'Bereit. Ein registrierter Agent kann die Datenbank abfragen, in der Sie angemeldet sind — verbinden Sie ihn neu, falls er bereits lief.',
	'mcp.register_on' => 'Beim Agenten registrieren unter',
	'mcp.register_hint' => 'Kopieren und einmal im Terminal ausführen. Funktioniert auch nach einem Neustart.',
	'mcp.readonly_note' => 'Ein Rollback macht Datenänderungen rückgängig. Manche Datenbanken bestätigen eine Strukturänderung sofort, dort lässt sie sich nicht zurücknehmen. Damit Schreibzugriffe unmöglich statt rückgängig gemacht werden, melden Sie sich als schreibgeschützter Datenbankbenutzer an.',
	'mcp.used_moments' => 'Letzte Abfrage: gerade eben.',
	'mcp.used_minutes' => 'Letzte Abfrage: innerhalb der letzten Stunde.',
	'mcp.used_hours' => 'Letzte Abfrage: heute.',
	'mcp.used_days' => 'Letzte Abfrage: vor über einem Tag.',
	'mcp.copy' => 'Kopieren',
	'mcp.copied' => 'Kopiert',
	'mcp.target' => 'Der Agent würde abfragen:',
	'mcp.write_enable' => 'Auch Daten ändern lassen (INSERT, UPDATE, DELETE)',
	'mcp.write_on' => 'Schreibvorgänge werden bestätigt. Der Agent wählt seine Anweisungen selbst, und ein falsches DELETE ist von hier aus nicht wiederherstellbar.',
	'mcp.write_off' => 'Nur Lesen. Anweisungen, die schreiben würden, werden ausgeführt und dann zurückgerollt, haben also keine Wirkung.',
];
