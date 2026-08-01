<?php
declare(strict_types=1);

// Plugin UI strings, Slovak. IDs mirror en.php (the base); an ID left out here falls back to
// English, so `make i18n-check` lists what is still missing.

return [
	// The plugin's own description, shown in Adminer's plugin list (AdminerDesktop::description()).
	'plugin.description' => 'Prispôsobí predvolené hodnoty pre desktopovú aplikáciu',

	// Settings dialog shell.
	'settings.title' => 'Nastavenia',
	'settings.tab_theme' => 'Vzhľad',
	'settings.tab_plugins' => 'Pluginy',
	'settings.save' => 'Uložiť',
	'settings.close' => 'Zavrieť',
	'settings.unsaved' => 'Neuložené zmeny: {n}. Napriek tomu zavrieť?',
	'settings.reset' => 'Obnoviť predvolené',
	'settings.reset_confirm' => 'Obnoviť všetky nastavenia na predvolené? Vzhľad, pluginy aj šírky, ktoré ste si upravili, sa zabudnú. Jazyk a uložené servery Admineru zostanú.',

	// Data list pager (src/Assets/javascript/table-pager.js): "1-20 of 50", the rows on screen
	// out of the rows there are.
	'pager.of' => 'z',

	// Theme panel.
	'theme.appearance' => 'Farebný režim',
	'theme.appearance_hint' => 'Adminer Desktop sa riadi svetlým a tmavým režimom systému, alebo ho môžete pripnúť na Svetlý či Tmavý. Každý režim použije vzhľad zvolený nižšie.',
	'theme.appearance_auto' => 'Podľa systému',
	'theme.light' => 'Svetlý',
	'theme.dark' => 'Tmavý',
	'theme.language' => 'Jazyk',
	'theme.density' => 'Hustota riadkov',
	'theme.density_compact' => 'Kompaktná',
	'theme.density_cozy' => 'Stredná',
	'theme.density_comfortable' => 'Vzdušná',
	'theme.scaling' => 'Mierka',
	'theme.design' => 'Vzhľad',
	'theme.preview' => 'Náhľad',
	// Product name; the same on both sides, but carried here so coverage stays complete.
	'theme.builtin_design' => 'Adminer Desktop',

	// Plugins panel.
	'plugins.col_name' => 'Plugin',
	'plugins.col_desc' => 'Čo robí',

	// Whole-page import dropzone.
	'import.drop_hint' => 'Presuňte sem SQL súbor na import',
];
