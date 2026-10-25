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
	'settings.tab_shortcuts' => 'Zkratky',
	'settings.save' => 'Uložit',
	'settings.close' => 'Zavřít',
	'modal.close' => 'Zavřít',
	'settings.reset' => 'Obnovit výchozí',
	'settings.reset_confirm' => 'Obnovit všechna nastavení na výchozí? Vzhled, pluginy i šířky, které jste si upravili, se zapomenou. Jazyk a uložené servery Admineru zůstanou.',
	'settings.load_failed' => 'Nastavení se nepodařilo načíst.',

	// Data list pager (src/Assets/javascript/table-pager.js): "1-20 of 50", the rows on screen
	// out of the rows there are.
	'js.pager.of' => 'z',

	// A long select narrowed by typing (src/Assets/javascript/select-filter.js).
	'js.select_filter.placeholder' => 'Napište část názvu',
	'js.select_filter.none' => 'Nic neodpovídá',

	'busy.loading' => 'Načítání…',
	'busy.stop' => 'Zastavit',

	// Saved connections.
	'connections.new' => 'Nové připojení',
	'connections.switch' => 'Změnit připojení',
	'connections.cancel' => 'Zrušit',
	'connections.stop_title' => 'Přestat se připojovat?',
	'connections.stop_text' => 'Server zatím neodpověděl. Co jste vyplnili, ve formuláři zůstane.',
	'connections.keep_waiting' => 'Čekat dál',
	'connections.stop' => 'Přestat',
	'connections.added' => 'Připojeno. Pojmenujte ho, ať ho snadno rozeznáte od ostatních.',
	'connections.empty' => 'Zatím žádná uložená připojení.',
	'connections.edit' => 'Upravit',
	'connections.name' => 'Název',
	'connections.environment' => 'Prostředí',
	'connections.color' => 'Barva',
	'connections.none' => 'Žádné',
	'connections.logout' => 'Odhlásit',
	'connections.logout_confirm' => 'Heslo se zapomene. Připojení zůstane v seznamu a příště se na heslo zeptá.',
	'connections.forget' => 'Zapomenout',
	'connections.forget_confirm' => 'Připojení zmizí ze seznamu i s heslem, názvem, prostředím a barvou.',
	'connections.signed_out' => 'odhlášeno',
	'connections.discard_title' => 'Zahodit změny?',
	'connections.discard_text' => 'Co jste tu změnili, se ztratí.',
	'connections.discard' => 'Zahodit',
	'connections.keep_editing' => 'Pokračovat v úpravách',

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
	'js.import.drop_hint' => 'Přetáhněte sem SQL soubor pro import',
	'js.sidebar.toggle' => 'Zobrazit nebo skrýt postranní panel',
	'js.toolbar.search' => 'Hledat',
	'js.history.back' => 'Zpět',
	'js.history.forward' => 'Vpřed',
	'js.pathbar.label' => 'Cesta',
	'js.sort.none' => 'Neřadit',

	// Letting an agent query the open database (Mcp\Panel, mcp-panel.latte).
	'settings.tab_mcp' => 'Přístup pro AI',
	'mcp.enable' => 'Povolit AI agentovi dotazovat se této databáze',
	'mcp.description' => 'Agent, kterého níže zaregistrujete, může číst uložená připojení, ke kterým jste přihlášeni — to, na kterém je toto okno, nebo jiné, které si sám zvolí — strukturu i dotazy, o které ho požádáte. Zapisovat nemůže: každý dotaz běží v transakci, která se vrátí zpět. Přístup trvá jen po dobu, kdy je okno otevřené a přihlášené.',
	'mcp.status_off' => 'Vypnuto. K databázi se nic nedostane.',
	'mcp.status_waiting' => 'Zapnuto, ale zatím nedostupné — přihlaste se k databázi a agent se může ptát.',
	'mcp.status_ready' => 'Připraveno. Zaregistrovaný agent se může ptát databáze, do které jste přihlášeni.',
	'mcp.register_on' => 'Zaregistrovat u agenta na',
	'mcp.register_hint' => 'Zkopírujte a spusťte jednou v terminálu. Funguje i po restartu.',
	'mcp.register_add' => 'Přidat',
	'mcp.register_remove' => 'Odebrat',
	'mcp.remove_hint' => 'Spusťte, když už agent nemá aplikaci spouštět, nebo před novým přidáním, pokud se aplikace přesunula: stará registrace dál ukazuje na původní cestu a agent hlásí jen, že je odpojená.',
	'mcp.readonly_note' => 'Vrácení transakce vrátí změny dat. Některé databáze potvrdí změnu struktury okamžitě, takže u nich ji vrátit nelze. Chcete-li zápis zcela znemožnit místo vracení, přihlaste se jako uživatel s právem jen ke čtení.',
	'mcp.whole_enable' => 'Vždy vracet dlouhé hodnoty celé',
	'mcp.whole_note' => 'Hodnoty delší než 2000 znaků se jinak zkracují, aby jeden velký sloupec nezaplnil kontext agenta. Agent si i tak může o celé hodnoty říct u jednotlivého dotazu.',
	'mcp.used_moments' => 'Poslední dotaz: před chvílí.',
	'mcp.used_minutes' => 'Poslední dotaz: během poslední hodiny.',
	'mcp.used_hours' => 'Poslední dotaz: dnes.',
	'mcp.used_days' => 'Poslední dotaz: před více než dnem.',
	'mcp.copy' => 'Kopírovat',
	'mcp.copied' => 'Zkopírováno',
	'mcp.target' => 'Agent by se ptal:',
	'mcp.write_enable' => 'Povolit trvalé změny a mazání dat (INSERT, UPDATE, DELETE)',
	'mcp.write_on' => 'Zápisy se potvrzují, kromě připojení označeného jako Prod, které zůstává jen pro čtení. Agent si příkazy volí sám a chybný DELETE odsud vrátit nelze.',
	'mcp.write_off' => 'Jen pro čtení. Příkazy, které by zapisovaly, se provedou a vrátí zpět, takže nemají žádný účinek.',

	'mcp.register_manual' => 'Funguje i jakýkoli jiný agent: zadejte mu stejný příkaz jako stdio MCP server v jeho vlastním konfiguračním souboru.',

	// Co se dozví agent, nikoli co ukazuje panel (Mcp\Stdio, Server, Tools, Endpoint). Čte to
	// člověk prostřednictvím agenta, proto se to překládá; názvy nástrojů a jejich popisy
	// nikoli — viz Desktop\I18n\Strings.
	'mcp.agent_not_running' => 'Adminer Desktop neběží, nebo je v jeho nastavení vypnutý přístup agentů k databázi. Otevřete aplikaci, přihlaste se a zapněte jej v Nastavení > Přístup AI, pak se zeptejte znovu — další volání projde bez nového připojení.',
	'mcp.agent_window_closed' => 'Adminer Desktop přestal odpovídat — okno bylo pravděpodobně zavřeno. Otevřete jej znovu a zeptejte se znovu; není třeba nic znovu připojovat.',
	'mcp.agent_timed_out' => 'Adminer Desktop neodpověděl do minuty, a tak bylo volání vzdáno. Pokud šlo o zápis, mohl se přesto provést — před dalším spuštěním to ověřte.',
	'mcp.agent_statement_too_long' => 'Příkaz běžel déle než {n} sekund, a tak ho databáze zastavila; u transakční tabulky z jeho změn nic nezůstalo. Zužte ho (WHERE, LIMIT, index), nebo požádejte uživatele, ať ho spustí v okně Adminer Desktop, kde žádný limit není.',
	'mcp.agent_session_expired' => 'Relace Adminer Desktopu vypršela. Přihlaste se v aplikaci znovu k databázi a pak se zeptejte znovu.',
	'mcp.agent_not_connected' => 'Adminer Desktop není připojen k databázi. Přihlaste se v aplikaci znovu.',
	'mcp.agent_note_committed' => 'Zápisy jsou povoleny: vše, co tento příkaz změnil, bylo potvrzeno.',
	'mcp.agent_note_rolled_back' => 'Toto proběhlo v transakci, která byla vrácena zpět. Nic nebylo zapsáno. Jakékoli id vrácené klauzulí RETURNING pochází ze sekvence a neoznačuje uložený řádek.',
	'mcp.agent_note_cut' => 'Hodnoty uvedené v truncated_cells byly zkráceny, aby jeden velký sloupec nezaplnil váš kontext. Pokud je potřebujete celé, zavolejte znovu s whole_values: true.',
	'mcp.agent_note_no_database' => 'Okno nemá otevřenou žádnou databázi, takže nástroje běží ve výchozí databázi serveru, pokud nějakou má. Chcete-li se dotazovat konkrétní databáze, předejte ji jako database: vypisuje je list_databases.',
	'mcp.agent_unknown_connection' => 'Není to přihlášené uložené připojení. Vypisuje je list_connections; nepřihlášené je nejdřív potřeba otevřít v okně Adminer Desktopu',
	'mcp.agent_forward_failed' => 'Adminer Desktop na tomto připojení volání nevyřídil. Připojení za to nemůže, příčinu najdete v logu aplikace',
	'mcp.agent_connect_failed' => 'Adminer Desktop se s tímto uloženým přihlášením nepřipojil: jeho server neběží, nebo heslo už neplatí. Adminer takové přihlášení odhlásí, proto požádejte uživatele, ať se k němu v okně znovu přihlásí, až server poběží',
	'mcp.agent_no_database' => 'Databázi se s tímto přihlášením nepodařilo otevřít',
	'mcp.agent_overprivileged' => 'Toto přihlášení se dostane mimo dotaz jen pro čtení — spustí program na databázovém serveru, přečte jeho soubory nebo hashe hesel — proto ho Adminer Desktop agentovi nepůjčí. Přihlaste okno rolí, která je nemá, nebo je této odeberte',
	'mcp.agent_privileges_unknown' => 'Adminer Desktop nedokázal ověřit, co toto přihlášení na serveru smí, proto ho agentovi nepůjčí',
	'mcp.agent_no_result' => 'příkaz nevrátil žádnou výsledkovou sadu',
	'mcp.agent_parse_error' => 'chyba při parsování',
	'mcp.agent_unknown_method' => 'neznámá metoda',
	'mcp.agent_unknown_tool' => 'neznámý nástroj',
	'mcp.agent_missing_argument' => 'chybí povinný argument',

	// Keyboard shortcuts tab (src/Shortcuts/).
	'shortcuts.group_app' => 'Aplikace',
	'shortcuts.group_forms' => 'Formuláře a SQL',
	'shortcuts.help' => 'Zobrazit tento seznam',
	'shortcuts.settings' => 'Otevřít nastavení',
	'shortcuts.reload' => 'Načíst stránku znovu',
	'shortcuts.close' => 'Zavřít dialog nebo menu',
	'shortcuts.adminer' => 'Přepnout na Adminer',
	'shortcuts.editor' => 'Přepnout na Editor',
	'shortcuts.logs' => 'Otevřít logy',
	'shortcuts.quit' => 'Ukončit',
	'shortcuts.submit' => 'Spustit SQL nebo uložit formulář',
	'shortcuts.insert_next' => 'Uložit a vložit další řádek',
	'shortcuts.field_up' => 'Přejít na pole výš',
	'shortcuts.field_down' => 'Přejít na pole níž',
	'shortcuts.search' => 'Hledat tabulky a databáze',

	// Search everywhere, on Shift twice (src/Search/).
	'search.title' => 'Hledat',
	'search.placeholder' => 'Napište část názvu',
	'search.table' => 'tabulka',
	'search.view' => 'pohled',
	'search.database' => 'databáze',
	'search.schema' => 'schéma',
	'search.none' => 'Nic neodpovídá',
];
