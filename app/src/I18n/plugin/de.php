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
	'plugins.readonly' => 'Der Plugin-Ordner ist schreibgeschützt.',
	'plugins.col_name' => 'Plugin',
	'plugins.col_desc' => 'Funktion',

	// Whole-page import dropzone.
	'import.drop_hint' => 'SQL-Datei zum Importieren hier ablegen',
];
