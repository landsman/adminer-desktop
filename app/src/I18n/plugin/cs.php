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
	'settings.reset' => 'Obnovit výchozí',
	'settings.reset_confirm' => 'Obnovit všechna nastavení na výchozí? Vzhled, pluginy i šířky, které jste si upravili, se zapomenou. Jazyk a uložené servery Admineru zůstanou.',

	// Data list pager (src/Assets/javascript/table-pager.js): "1-20 of 50", the rows on screen
	// out of the rows there are.
	'pager.of' => 'z',

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

	// Letting an agent query the open database (Mcp\Panel, mcp-panel.latte).
	'settings.tab_mcp' => 'Přístup pro AI',
	'mcp.enable' => 'Povolit AI agentovi dotazovat se této databáze',
	'mcp.description' => 'Agent, kterého níže zaregistrujete, může číst databázi, do které je toto okno přihlášeno — strukturu i dotazy, o které ho požádáte. Zapisovat nemůže: každý dotaz běží v transakci, která se vrátí zpět. Přístup trvá jen po dobu, kdy je okno otevřené a přihlášené.',
	'mcp.status_off' => 'Vypnuto. K databázi se nic nedostane.',
	'mcp.status_waiting' => 'Zapnuto, ale zatím nedostupné — přihlaste se k databázi a agent se může ptát.',
	'mcp.status_ready' => 'Připraveno. Zaregistrovaný agent se může ptát databáze, do které jste přihlášeni.',
	'mcp.register_on' => 'Zaregistrovat u agenta na',
	'mcp.register_hint' => 'Zkopírujte a spusťte jednou v terminálu. Funguje i po restartu.',
	'mcp.readonly_note' => 'Vrácení transakce vrátí změny dat. Některé databáze potvrdí změnu struktury okamžitě, takže u nich ji vrátit nelze. Chcete-li zápis zcela znemožnit místo vracení, přihlaste se jako uživatel s právem jen ke čtení.',
	'mcp.used_moments' => 'Poslední dotaz: před chvílí.',
	'mcp.used_minutes' => 'Poslední dotaz: během poslední hodiny.',
	'mcp.used_hours' => 'Poslední dotaz: dnes.',
	'mcp.used_days' => 'Poslední dotaz: před více než dnem.',
	'mcp.copy' => 'Kopírovat',
	'mcp.copied' => 'Zkopírováno',
	'mcp.target' => 'Agent by se ptal:',
];
