<?php
declare(strict_types=1);

// Plugin UI strings, Czech. IDs mirror en.php (the base); an ID left out here falls back to
// English, so `make i18n-check` lists what is still missing.

return [
	// The plugin's own description, shown in Adminer's plugin list (AdminerDesktop::description()).
	'plugin.description' => 'Přizpůsobí výchozí hodnoty pro desktopovou aplikaci',

	// Settings dialog shell.
	'settings.title' => 'Nastavení',
	'settings.tab_theme' => 'Vzhled',
	'settings.tab_plugins' => 'Pluginy',
	'settings.save' => 'Uložit',
	'settings.close' => 'Zavřít',
	'settings.unsaved' => 'Neuložené změny: {n}. Přesto zavřít?',

	// Theme panel.
	'theme.appearance' => 'Barevný režim',
	'theme.appearance_hint' => 'Adminer Desktop se řídí světlým a tmavým režimem systému, nebo ho můžete připnout na Světlý či Tmavý. Každý režim použije vzhled zvolený níže.',
	'theme.appearance_auto' => 'Podle systému',
	'theme.light' => 'Světlý',
	'theme.dark' => 'Tmavý',
	'theme.language' => 'Jazyk',
	'theme.density' => 'Hustota řádků',
	'theme.density_compact' => 'Kompaktní',
	'theme.density_cozy' => 'Střední',
	'theme.density_comfortable' => 'Vzdušný',
	'theme.scaling' => 'Měřítko',
	'theme.design' => 'Vzhled',
	'theme.preview' => 'Náhled',
	// Product name; the same on both sides, but carried here so coverage stays complete.
	'theme.builtin_design' => 'Adminer Desktop',

	// Plugins panel.
	'plugins.col_name' => 'Plugin',
	'plugins.col_desc' => 'Co dělá',

	// Whole-page import dropzone.
	'import.drop_hint' => 'Přetáhněte sem SQL soubor pro import',
];
