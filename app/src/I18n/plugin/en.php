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
	'settings.tab_theme' => 'Appearance',
	'settings.tab_plugins' => 'Plugins',
	'settings.tab_shortcuts' => 'Shortcuts',
	'settings.save' => 'Save',
	'settings.close' => 'Cancel',
	// {n}, not %d: Adminer's lang() runs the string through sprintf, which would replace %d with 0
	// before the browser (which fills in {n}) ever sees it.
	'settings.reset' => 'Reset to defaults',
	'settings.reset_confirm' => 'Reset every setting back to its default? The theme, the plugins and the widths you resized are all forgotten. Adminer\'s own language and saved servers are kept.',

	// Data list pager (src/Assets/javascript/table-pager.js): "1-20 of 50", the rows on screen
	// out of the rows there are.
	'pager.of' => 'of',

	// Saved connections on the login page (src/Connections/).
	'connections.new' => 'New connection',
	'connections.empty' => 'No saved connections yet.',
	'connections.edit' => 'Edit',
	'connections.name' => 'Name',
	'connections.environment' => 'Environment',
	'connections.color' => 'Colour',
	'connections.none' => 'None',
	'connections.logout' => 'Log out',
	'connections.logout_confirm' => 'The password is forgotten. The connection stays on the list and asks for it next time.',
	'connections.forget' => 'Forget',
	'connections.forget_confirm' => 'The connection is removed from the list, with its password, name, environment and colour.',
	'connections.signed_out' => 'logged out',
	'connections.discard_title' => 'Discard changes?',
	'connections.discard_text' => 'What you changed here will be lost.',
	'connections.discard' => 'Discard',
	'connections.keep_editing' => 'Keep editing',

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
	'mcp.whole_enable' => 'Always return long values whole',
	'mcp.whole_note' => 'Values longer than 2000 characters are otherwise cut, so one large column does not fill the agent\'s context. The agent can still ask for whole values per query.',
	'mcp.used_moments' => 'Last query: moments ago.',
	'mcp.used_minutes' => 'Last query: within the hour.',
	'mcp.used_hours' => 'Last query: today.',
	'mcp.used_days' => 'Last query: over a day ago.',
	'mcp.copy' => 'Copy',
	'mcp.copied' => 'Copied',
	'mcp.target' => 'The agent would query:',
	'mcp.write_enable' => 'Let it change and permanently delete data (INSERT, UPDATE, DELETE)',
	'mcp.write_on' => 'Writes are committed. The agent decides its own statements, and a wrong DELETE is not recoverable from here.',
	'mcp.write_off' => 'Read-only. Statements that would write are run and then rolled back, so they have no effect.',

	'mcp.register_manual' => 'Any other agent works too: give it the same command as a stdio MCP server in its own configuration file.',

	// What an agent is told, rather than what the panel shows (Mcp\Stdio, Server, Tools,
	// Endpoint). Read by a person through the agent, so translated; the tool names and their
	// descriptions are not — see Desktop\I18n\Strings.
	'mcp.agent_not_running' => 'Adminer Desktop is not running, or database access for agents is switched off in its settings. Open the app, log in, and switch it on under Settings > AI access — then reconnect this server, because the tool list is read once when the connection opens.',
	'mcp.agent_window_closed' => 'Adminer Desktop stopped answering — the window was probably closed. Open it again and reconnect this server; the registration itself keeps working.',
	'mcp.agent_session_expired' => 'The Adminer Desktop session has expired. Log in to the database again in the app, then reconnect this server.',
	'mcp.agent_not_connected' => 'Adminer Desktop is not connected to a database. Log in again in the app.',
	'mcp.agent_note_committed' => 'Writes are enabled: anything this statement changed has been committed.',
	'mcp.agent_note_rolled_back' => 'This ran inside a transaction that was rolled back. Nothing was written. Any id returned by RETURNING came from a sequence and does not identify a stored row.',
	'mcp.agent_note_cut' => 'Values listed in truncated_cells were cut so that one large column does not fill your context. Call again with whole_values: true if you need them whole.',
	'mcp.agent_no_result' => 'the statement returned no result set',
	'mcp.agent_parse_error' => 'parse error',
	'mcp.agent_unknown_method' => 'unknown method',
	'mcp.agent_unknown_tool' => 'unknown tool',
	'mcp.agent_missing_argument' => 'missing required argument',

	// Keyboard shortcuts tab (src/Shortcuts/). Adminer and Editor are product names.
	'shortcuts.group_app' => 'App',
	'shortcuts.group_forms' => 'Forms and SQL',
	'shortcuts.help' => 'Show this list',
	'shortcuts.reload' => 'Reload the page',
	'shortcuts.close' => 'Close a dialog or a menu',
	'shortcuts.adminer' => 'Switch to Adminer',
	'shortcuts.editor' => 'Switch to Editor',
	'shortcuts.logs' => 'Open the logs',
	'shortcuts.quit' => 'Quit',
	'shortcuts.submit' => 'Run the SQL, or save the form',
	'shortcuts.insert_next' => 'Save and insert the next row',
	'shortcuts.field_up' => 'Move to the field above',
	'shortcuts.field_down' => 'Move to the field below',
	'shortcuts.search' => 'Search tables and databases',

	// Search everywhere, on Shift twice (src/Search/). The kinds label each match.
	'search.title' => 'Search',
	'search.placeholder' => 'Type part of a name',
	'search.table' => 'table',
	'search.view' => 'view',
	'search.database' => 'database',
	'search.schema' => 'schema',
	'search.none' => 'Nothing matches',
];
