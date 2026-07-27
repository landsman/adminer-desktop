<?php
declare(strict_types=1);

// Plugin UI strings, Romanian. IDs mirror en.php (the base); an ID left out here falls back to
// English, so `make i18n-check` lists what is still missing.

return [
	// The plugin's own description, shown in Adminer's plugin list (AdminerDesktop::description()).
	'plugin.description' => 'Adaptează valorile implicite ale Adminer pentru rularea ca aplicație desktop.',

	// Settings dialog shell.
	'settings.title' => 'Setări',
	'settings.tab_theme' => 'Aspect',
	'settings.tab_plugins' => 'Pluginuri',
	'settings.save' => 'Salvează',
	'settings.close' => 'Închide',
	'settings.unsaved' => 'Modificări nesalvate: {n}. Închideți oricum?',

	// Theme panel.
	'theme.appearance' => 'Mod de culoare',
	'theme.appearance_hint' => 'Adminer Desktop urmează modul luminos și întunecat al sistemului sau îl puteți fixa pe Luminos ori Întunecat. Fiecare mod folosește tema aleasă mai jos.',
	'theme.appearance_auto' => 'Sincronizat cu sistemul',
	'theme.light' => 'Luminos',
	'theme.dark' => 'Întunecat',
	'theme.language' => 'Limbă',
	'theme.density' => 'Densitatea rândurilor',
	'theme.density_compact' => 'Compactă',
	'theme.density_cozy' => 'Medie',
	'theme.density_comfortable' => 'Spațioasă',
	'theme.scaling' => 'Scalare',
	'theme.design' => 'Temă',
	'theme.preview' => 'Previzualizare',
	// Product name; the same on both sides, but carried here so coverage stays complete.
	'theme.builtin_design' => 'Adminer Desktop',

	// Plugins panel.
	'plugins.col_name' => 'Plugin',
	'plugins.col_desc' => 'Ce face',

	// Whole-page import dropzone.
	'import.drop_hint' => 'Trageți aici fișierul SQL pentru import',
];
