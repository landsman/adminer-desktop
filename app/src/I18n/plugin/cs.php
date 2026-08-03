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
	'mcp.status_ready' => 'Připraveno. Zaregistrovaný agent se může ptát databáze, do které jste přihlášeni — pokud už běžel, znovu ho připojte.',
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
	'mcp.write_enable' => 'Povolit také měnit data (INSERT, UPDATE, DELETE)',
	'mcp.write_on' => 'Zápisy se potvrzují. Agent si příkazy volí sám a chybný DELETE odsud vrátit nelze.',
	'mcp.write_off' => 'Jen pro čtení. Příkazy, které by zapisovaly, se provedou a vrátí zpět, takže nemají žádný účinek.',

	'mcp.register_manual' => 'Funguje i jakýkoli jiný agent: zadejte mu stejný příkaz jako stdio MCP server v jeho vlastním konfiguračním souboru.',

	// Co se dozví agent, nikoli co ukazuje panel (Mcp\Stdio, Server, Tools, Endpoint). Čte to
	// člověk prostřednictvím agenta, proto se to překládá; názvy nástrojů a jejich popisy
	// nikoli — viz Desktop\I18n\Strings.
	'mcp.agent_not_running' => 'Adminer Desktop neběží, nebo je v jeho nastavení vypnutý přístup agentů k databázi. Otevřete aplikaci, přihlaste se a zapněte jej v Nastavení > Přístup AI — pak tento server znovu připojte, protože seznam nástrojů se načítá jednou při otevření spojení.',
	'mcp.agent_window_closed' => 'Adminer Desktop přestal odpovídat — okno bylo pravděpodobně zavřeno. Otevřete jej znovu a znovu připojte tento server; samotná registrace funguje dál.',
	'mcp.agent_session_expired' => 'Relace Adminer Desktopu vypršela. Přihlaste se v aplikaci znovu k databázi a poté znovu připojte tento server.',
	'mcp.agent_not_connected' => 'Adminer Desktop není připojen k databázi. Přihlaste se v aplikaci znovu.',
	'mcp.agent_note_committed' => 'Zápisy jsou povoleny: vše, co tento příkaz změnil, bylo potvrzeno.',
	'mcp.agent_note_rolled_back' => 'Toto proběhlo v transakci, která byla vrácena zpět. Nic nebylo zapsáno. Jakékoli id vrácené klauzulí RETURNING pochází ze sekvence a neoznačuje uložený řádek.',
	'mcp.agent_no_result' => 'příkaz nevrátil žádnou výsledkovou sadu',
	'mcp.agent_parse_error' => 'chyba při parsování',
	'mcp.agent_unknown_method' => 'neznámá metoda',
	'mcp.agent_unknown_tool' => 'neznámý nástroj',
	'mcp.agent_missing_argument' => 'chybí povinný argument',
];
