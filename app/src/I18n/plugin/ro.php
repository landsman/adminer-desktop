<?php
declare(strict_types=1);

// Plugin UI strings, Romanian. IDs mirror en.php (the base); an ID left out here falls back to
// English, so `make i18n-check` lists what is still missing.

return [
	// The plugin's own description, shown in Adminer's plugin list (AdminerDesktop::description()).
	'plugin.description' => 'Adaptează valorile implicite ale Adminer pentru rularea ca aplicație desktop.',

	// Settings dialog shell.
	'settings.title' => 'Setări',
	'settings.tab_theme' => 'Aspect',
	'settings.tab_plugins' => 'Pluginuri',
	'settings.save' => 'Salvează',
	'settings.close' => 'Închide',
	'settings.unsaved' => 'Modificări nesalvate: {n}. Închideți oricum?',
	'settings.reset' => 'Resetează la implicit',
	'settings.reset_confirm' => 'Resetați toate setările la valorile implicite? Aspectul, pluginurile și lățimile modificate de dumneavoastră se pierd. Limba și serverele salvate în Adminer rămân.',

	// Data list pager (src/Assets/javascript/table-pager.js): "1-20 of 50", the rows on screen
	// out of the rows there are.
	'pager.of' => 'din',

	// Theme panel.
	'theme.appearance' => 'Mod de culoare',
	'theme.appearance_hint' => 'Adminer Desktop urmează modul luminos și întunecat al sistemului sau îl puteți fixa pe Luminos ori Întunecat. Fiecare mod folosește tema aleasă mai jos.',
	'theme.appearance_auto' => 'Sincronizat cu sistemul',
	'theme.light' => 'Luminos',
	'theme.dark' => 'Întunecat',
	'theme.language' => 'Limbă',
	'theme.density' => 'Densitatea rândurilor',
	'theme.density_compact' => 'Compactă',
	'theme.density_cozy' => 'Medie',
	'theme.density_comfortable' => 'Spațioasă',
	'theme.scaling' => 'Scalare',
	'theme.design' => 'Temă',
	'theme.preview' => 'Previzualizare',
	// Product name; the same on both sides, but carried here so coverage stays complete.
	'theme.builtin_design' => 'Adminer Desktop',

	// Plugins panel.
	'plugins.col_name' => 'Plugin',
	'plugins.col_desc' => 'Ce face',

	// Whole-page import dropzone.
	'import.drop_hint' => 'Trageți aici fișierul SQL pentru import',

	// Letting an agent query the open database (Mcp\Panel, mcp-panel.latte).
	'settings.tab_mcp' => 'Acces AI',
	'mcp.enable' => 'Permite unui agent AI să interogheze această bază de date',
	'mcp.description' => 'Un agent înregistrat mai jos poate citi baza de date în care este autentificată această fereastră — structura și interogările pe care i le ceri. Nu poate scrie: fiecare interogare rulează într-o tranzacție care este anulată. Accesul durează doar cât timp fereastra este deschisă și autentificată.',
	'mcp.status_off' => 'Oprit. Nimic nu ajunge la baza de date.',
	'mcp.status_waiting' => 'Pornit, dar încă inaccesibil — autentifică-te la o bază de date și agentul o va putea interoga.',
	'mcp.status_ready' => 'Gata. Un agent înregistrat poate interoga baza de date în care ești autentificat — reconectează-l dacă rula deja.',
	'mcp.register_on' => 'Înregistrează la agentul tău pe',
	'mcp.register_hint' => 'Copiază-l și rulează-l o dată în terminal. Funcționează și după repornire.',
	'mcp.readonly_note' => 'Anularea tranzacției revine asupra modificărilor de date. Unele baze de date confirmă imediat o modificare de structură, așa că acolo nu poate fi anulată. Pentru ca scrierile să fie imposibile, nu doar anulate, autentifică-te ca utilizator de bază de date doar cu drept de citire.',
	'mcp.used_moments' => 'Ultima interogare: chiar acum.',
	'mcp.used_minutes' => 'Ultima interogare: în ultima oră.',
	'mcp.used_hours' => 'Ultima interogare: astăzi.',
	'mcp.used_days' => 'Ultima interogare: acum mai bine de o zi.',
	'mcp.copy' => 'Copiază',
	'mcp.copied' => 'Copiat',
	'mcp.target' => 'Agentul ar interoga:',
	'mcp.write_enable' => 'Permite modificarea și ștergerea definitivă a datelor (INSERT, UPDATE, DELETE)',
	'mcp.write_on' => 'Scrierile sunt confirmate. Agentul își alege singur instrucțiunile, iar un DELETE greșit nu poate fi recuperat de aici.',
	'mcp.write_off' => 'Doar citire. Instrucțiunile care ar scrie sunt rulate și apoi anulate, deci nu au niciun efect.',

	'mcp.register_manual' => 'Funcționează și orice alt agent: dați-i aceeași comandă drept server MCP pe stdio, în propriul lui fișier de configurare.',

	// Ce află agentul, nu ce arată panoul (Mcp\Stdio, Server, Tools, Endpoint). Este citit de
	// un om prin intermediul agentului, deci se traduce; numele uneltelor și descrierile lor
	// nu — vezi Desktop\I18n\Strings.
	'mcp.agent_not_running' => 'Adminer Desktop nu rulează sau accesul agenților la bază este dezactivat în setările sale. Deschideți aplicația, autentificați-vă și activați-l din Setări > Acces AI — apoi reconectați acest server, fiindcă lista de unelte este citită o singură dată, la deschiderea conexiunii.',
	'mcp.agent_window_closed' => 'Adminer Desktop a încetat să răspundă — fereastra a fost probabil închisă. Deschideți-o din nou și reconectați acest server; înregistrarea în sine rămâne valabilă.',
	'mcp.agent_session_expired' => 'Sesiunea Adminer Desktop a expirat. Autentificați-vă din nou la baza de date în aplicație, apoi reconectați acest server.',
	'mcp.agent_not_connected' => 'Adminer Desktop nu este conectat la nicio bază de date. Autentificați-vă din nou în aplicație.',
	'mcp.agent_note_committed' => 'Scrierile sunt permise: tot ce a modificat această instrucțiune a fost confirmat.',
	'mcp.agent_note_rolled_back' => 'Aceasta a rulat într-o tranzacție anulată. Nu s-a scris nimic. Orice id returnat de RETURNING provine dintr-o secvență și nu identifică un rând stocat.',
	'mcp.agent_no_result' => 'instrucțiunea nu a returnat niciun set de rezultate',
	'mcp.agent_parse_error' => 'eroare de analiză',
	'mcp.agent_unknown_method' => 'metodă necunoscută',
	'mcp.agent_unknown_tool' => 'unealtă necunoscută',
	'mcp.agent_missing_argument' => 'lipsește un argument obligatoriu',
];
