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
	'settings.reset' => 'Reset to defaults',
	'settings.reset_confirm' => 'Reset every setting back to its default? The theme, the plugins and the widths you resized are all forgotten. Adminer\'s own language and saved servers are kept.',

	// Data list pager (src/Assets/javascript/table-pager.js): "1-20 of 50", the rows on screen
	// out of the rows there are.
	'pager.of' => 'of',

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
	'plugins.col_name' => 'Plugin',
	'plugins.col_desc' => 'What it does',

	// Whole-page import dropzone (AdminerDesktop::head()).
	'import.drop_hint' => 'Drop the SQL file to import',

	// Letting an agent query the open database (Mcp\Panel, mcp-panel.latte).
	'settings.tab_mcp' => 'AI access',
	'mcp.enable' => 'Let an AI agent query this database',
	'mcp.description' => 'An agent you register below can read the database this window is logged into — the schema, and queries you ask it to run. It cannot write: every query runs in a transaction that is rolled back. Access lasts only while this window is open and logged in.',
	'mcp.status_off' => 'Off. Nothing can reach the database.',
	'mcp.status_waiting' => 'On, but not reachable yet — log in to a database and the agent can query it.',
	'mcp.status_ready' => 'Ready. A registered agent can query the database you are logged into — reconnect it if it was already running.',
	'mcp.register_on' => 'Register with your agent on',
	'mcp.register_hint' => 'Copy it, then run it once in a terminal. It keeps working after a restart.',
	'mcp.readonly_note' => 'Rollback undoes data changes. Some databases commit a schema change immediately, so on those it cannot be undone. To make writes impossible rather than undone, log in as a read-only database user.',
	'mcp.used_moments' => 'Last query: moments ago.',
	'mcp.used_minutes' => 'Last query: within the hour.',
	'mcp.used_hours' => 'Last query: today.',
	'mcp.used_days' => 'Last query: over a day ago.',
	'mcp.copy' => 'Copy',
	'mcp.copied' => 'Copied',
	'mcp.target' => 'The agent would query:',
];
