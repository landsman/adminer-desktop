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

	// Letting an agent query the open database (Mcp\Panel, mcp-panel.latte).
	'settings.tab_mcp' => 'Prístup pre AI',
	'mcp.enable' => 'Povoliť AI agentovi dopytovať sa tejto databázy',
	'mcp.description' => 'Agent, ktorého nižšie zaregistrujete, môže čítať databázu, do ktorej je toto okno prihlásené — štruktúru aj dopyty, o ktoré ho požiadate. Zapisovať nemôže: každý dopyt beží v transakcii, ktorá sa vráti späť. Prístup trvá len počas toho, kým je okno otvorené a prihlásené.',
	'mcp.status_off' => 'Vypnuté. K databáze sa nič nedostane.',
	'mcp.status_waiting' => 'Zapnuté, ale zatiaľ nedostupné — prihláste sa k databáze a agent sa môže pýtať.',
	'mcp.status_ready' => 'Pripravené. Zaregistrovaný agent sa môže pýtať databázy, do ktorej ste prihlásení — ak už bežal, znova ho pripojte.',
	'mcp.register_on' => 'Zaregistrovať u agenta na',
	'mcp.register_hint' => 'Skopírujte a spustite raz v termináli. Funguje aj po reštarte.',
	'mcp.readonly_note' => 'Vrátenie transakcie vráti zmeny dát. Niektoré databázy potvrdia zmenu štruktúry okamžite, takže pri nich ju vrátiť nemožno. Ak chcete zápis úplne znemožniť namiesto vracania, prihláste sa ako používateľ s právom len na čítanie.',
	'mcp.used_moments' => 'Posledný dopyt: pred chvíľou.',
	'mcp.used_minutes' => 'Posledný dopyt: počas poslednej hodiny.',
	'mcp.used_hours' => 'Posledný dopyt: dnes.',
	'mcp.used_days' => 'Posledný dopyt: pred viac ako dňom.',
	'mcp.copy' => 'Kopírovať',
	'mcp.copied' => 'Skopírované',
	'mcp.target' => 'Agent by sa pýtal:',
	'mcp.write_enable' => 'Povoliť aj meniť dáta (INSERT, UPDATE, DELETE)',
	'mcp.write_on' => 'Zápisy sa potvrdzujú. Agent si príkazy volí sám a chybný DELETE sa odtiaľto vrátiť nedá.',
	'mcp.write_off' => 'Len na čítanie. Príkazy, ktoré by zapisovali, sa vykonajú a vrátia späť, takže nemajú žiadny účinok.',
];
