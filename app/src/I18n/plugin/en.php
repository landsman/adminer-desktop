<?php
declare(strict_types=1);

// Plugin UI strings, English — the base locale. Keys are stable dotted IDs (topic.name), not the
// English text, so the wording can change here without touching every call site. Fed to Adminer's
// Plugin::lang() as AdminerDesktop::$translations, so a $desktop->t('settings.save') on the login
// page or in the settings dialog resolves against these; a missing ID degrades to the English here.
//
// This is the runtime-served counterpart to the compiled native/ strings; both share the Locale
// enum and the Catalog loader. The plugin description is not here: Adminer takes English from the
// AdminerDesktop class docblock (its '' translation lives in cs.php only).

return [
	// The plugin's own description, shown in Adminer's plugin list (AdminerDesktop::description()).
	'plugin.description' => "Adapt Adminer's defaults to running as a desktop app.",

	// Settings dialog shell (settings-dialog.latte, Dialog.php).
	'settings.title' => 'Settings',
	'settings.tab_theme' => 'Theme',
	'settings.tab_plugins' => 'Plugins',
	'settings.save' => 'Save',
	'settings.close' => 'Cancel',
	// {n}, not %d: Adminer's lang() runs the string through sprintf, which would replace %d with 0
	// before the browser (which fills in {n}) ever sees it.
	'settings.unsaved' => 'Unsaved changes: {n}. Close anyway?',

	// Theme panel (theme-panel.latte, Theme.php).
	'theme.appearance' => 'Appearance',
	'theme.appearance_hint' => 'Adminer Desktop follows the system light and dark, or pin it to Light or Dark. Either scheme uses the design chosen for it below.',
	'theme.appearance_auto' => 'Sync with OS',
	'theme.light' => 'Light',
	'theme.dark' => 'Dark',
	'theme.language' => 'Language',
	'theme.density' => 'Row density',
	'theme.density_compact' => 'Compact',
	'theme.density_cozy' => 'Cozy',
	'theme.density_comfortable' => 'Comfortable',
	'theme.scaling' => 'Scaling',
	'theme.design' => 'Design',
	'theme.preview' => 'Preview',
	'theme.builtin_design' => 'Adminer Desktop',

	// Plugins panel (plugins-panel.latte).
	'plugins.readonly' => 'The plugins folder is read-only.',
	'plugins.col_name' => 'Plugin',
	'plugins.col_desc' => 'What it does',

	// Whole-page import dropzone (AdminerDesktop::head()).
	'import.drop_hint' => 'Drop the SQL file to import',
];
