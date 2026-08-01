<?php
declare(strict_types=1);

// Plugin UI strings, Polish. IDs mirror en.php (the base); an ID left out here falls back to
// English, so `make i18n-check` lists what is still missing.

return [
	// The plugin's own description, shown in Adminer's plugin list (AdminerDesktop::description()).
	'plugin.description' => 'Dostosowuje domyślne ustawienia Adminera do pracy jako aplikacja desktopowa.',

	// Settings dialog shell.
	'settings.title' => 'Ustawienia',
	'settings.tab_theme' => 'Wygląd',
	'settings.tab_plugins' => 'Wtyczki',
	'settings.save' => 'Zapisz',
	'settings.close' => 'Zamknij',
	'settings.unsaved' => 'Niezapisane zmiany: {n}. Zamknąć mimo to?',
	'settings.reset' => 'Przywróć domyślne',
	'settings.reset_confirm' => 'Przywrócić wszystkie ustawienia do domyślnych? Wygląd, wtyczki i zmienione przez Ciebie szerokości zostaną zapomniane. Język i zapisane serwery Adminera pozostaną.',

	// Data list pager (src/Assets/javascript/table-pager.js): "1-20 of 50", the rows on screen
	// out of the rows there are.
	'pager.of' => 'z',

	// Theme panel.
	'theme.appearance' => 'Tryb kolorów',
	'theme.appearance_hint' => 'Adminer Desktop podąża za jasnym i ciemnym trybem systemu, albo można go ustawić na stałe na Jasny lub Ciemny. Każdy tryb używa motywu wybranego poniżej.',
	'theme.appearance_auto' => 'Zgodnie z systemem',
	'theme.light' => 'Jasny',
	'theme.dark' => 'Ciemny',
	'theme.language' => 'Język',
	'theme.density' => 'Gęstość wierszy',
	'theme.density_compact' => 'Kompaktowa',
	'theme.density_cozy' => 'Średnia',
	'theme.density_comfortable' => 'Przestronna',
	'theme.scaling' => 'Skalowanie',
	'theme.design' => 'Motyw',
	'theme.preview' => 'Podgląd',
	// Product name; the same on both sides, but carried here so coverage stays complete.
	'theme.builtin_design' => 'Adminer Desktop',

	// Plugins panel.
	'plugins.col_name' => 'Wtyczka',
	'plugins.col_desc' => 'Co robi',

	// Whole-page import dropzone.
	'import.drop_hint' => 'Upuść tutaj plik SQL, aby go zaimportować',
];
