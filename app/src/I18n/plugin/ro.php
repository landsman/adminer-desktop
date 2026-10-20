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
	'settings.tab_shortcuts' => 'Comenzi rapide',
	'settings.save' => 'Salvează',
	'settings.close' => 'Închide',
	'modal.close' => 'Închide',
	'settings.reset' => 'Resetează la implicit',
	'settings.reset_confirm' => 'Resetați toate setările la valorile implicite? Aspectul, pluginurile și lățimile modificate de dumneavoastră se pierd. Limba și serverele salvate în Adminer rămân.',

	// Data list pager (src/Assets/javascript/table-pager.js): "1-20 of 50", the rows on screen
	// out of the rows there are.
	'js.pager.of' => 'din',

	// A long select narrowed by typing (src/Assets/javascript/select-filter.js).
	'js.select_filter.placeholder' => 'Scrie o parte din nume',
	'js.select_filter.none' => 'Nicio potrivire',

	'busy.loading' => 'Se încarcă…',

	// Saved connections.
	'connections.new' => 'Conexiune nouă',
	'connections.added' => 'Conectat. Dă-i un nume, ca s-o deosebești ușor de celelalte.',
	'connections.empty' => 'Încă nu există conexiuni salvate.',
	'connections.edit' => 'Editează',
	'connections.name' => 'Nume',
	'connections.environment' => 'Mediu',
	'connections.color' => 'Culoare',
	'connections.none' => 'Niciunul',
	'connections.logout' => 'Deconectare',
	'connections.logout_confirm' => 'Parola este uitată. Conexiunea rămâne în listă și o cere data viitoare.',
	'connections.forget' => 'Uită',
	'connections.forget_confirm' => 'Conexiunea este scoasă din listă, cu parola, numele, mediul și culoarea ei.',
	'connections.signed_out' => 'deconectat',
	'connections.discard_title' => 'Renunți la modificări?',
	'connections.discard_text' => 'Ce ai modificat aici se va pierde.',
	'connections.discard' => 'Renunță',
	'connections.keep_editing' => 'Continuă editarea',

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
	'js.import.drop_hint' => 'Trageți aici fișierul SQL pentru import',
	'js.sidebar.toggle' => 'Afișează sau ascunde bara laterală',

	// Letting an agent query the open database (Mcp\Panel, mcp-panel.latte).
	'settings.tab_mcp' => 'Acces AI',
	'mcp.enable' => 'Permite unui agent AI să interogheze această bază de date',
	'mcp.description' => 'Un agent înregistrat mai jos poate citi conexiunile salvate în care ești autentificat — cea a acestei ferestre sau alta pe care o alege singur — structura și interogările pe care i le ceri. Nu poate scrie: fiecare interogare rulează într-o tranzacție care este anulată. Accesul durează doar cât timp fereastra este deschisă și autentificată.',
	'mcp.status_off' => 'Oprit. Nimic nu ajunge la baza de date.',
	'mcp.status_waiting' => 'Pornit, dar încă inaccesibil — autentifică-te la o bază de date și agentul o va putea interoga.',
	'mcp.status_ready' => 'Gata. Un agent înregistrat poate interoga baza de date în care ești autentificat.',
	'mcp.register_on' => 'Înregistrează la agentul tău pe',
	'mcp.register_hint' => 'Copiază-l și rulează-l o dată în terminal. Funcționează și după repornire.',
	'mcp.register_add' => 'Adaugă',
	'mcp.register_remove' => 'Elimină',
	'mcp.remove_hint' => 'Rulează-l când agentul nu mai trebuie să pornească aplicația sau înainte de a o adăuga din nou dacă aplicația a fost mutată: vechea înregistrare indică în continuare calea veche, iar agentul raportează doar că este deconectată.',
	'mcp.readonly_note' => 'Anularea tranzacției revine asupra modificărilor de date. Unele baze de date confirmă imediat o modificare de structură, așa că acolo nu poate fi anulată. Pentru ca scrierile să fie imposibile, nu doar anulate, autentifică-te ca utilizator de bază de date doar cu drept de citire.',
	'mcp.whole_enable' => 'Returnează întotdeauna valorile lungi întregi',
	'mcp.whole_note' => 'Altfel, valorile mai lungi de 2000 de caractere sunt trunchiate, ca o singură coloană mare să nu umple contextul agentului. Agentul poate cere în continuare valori întregi pentru fiecare interogare.',
	'mcp.used_moments' => 'Ultima interogare: chiar acum.',
	'mcp.used_minutes' => 'Ultima interogare: în ultima oră.',
	'mcp.used_hours' => 'Ultima interogare: astăzi.',
	'mcp.used_days' => 'Ultima interogare: acum mai bine de o zi.',
	'mcp.copy' => 'Copiază',
	'mcp.copied' => 'Copiat',
	'mcp.target' => 'Agentul ar interoga:',
	'mcp.write_enable' => 'Permite modificarea și ștergerea definitivă a datelor (INSERT, UPDATE, DELETE)',
	'mcp.write_on' => 'Scrierile sunt confirmate, cu excepția unei conexiuni marcate Prod, care rămâne doar pentru citire. Agentul își alege singur instrucțiunile, iar un DELETE greșit nu poate fi recuperat de aici.',
	'mcp.write_off' => 'Doar citire. Instrucțiunile care ar scrie sunt rulate și apoi anulate, deci nu au niciun efect.',

	'mcp.register_manual' => 'Funcționează și orice alt agent: dați-i aceeași comandă drept server MCP pe stdio, în propriul lui fișier de configurare.',

	// Ce află agentul, nu ce arată panoul (Mcp\Stdio, Server, Tools, Endpoint). Este citit de
	// un om prin intermediul agentului, deci se traduce; numele uneltelor și descrierile lor
	// nu — vezi Desktop\I18n\Strings.
	'mcp.agent_not_running' => 'Adminer Desktop nu rulează sau accesul agenților la bază este dezactivat în setările sale. Deschideți aplicația, autentificați-vă și activați-l din Setări > Acces AI, apoi întrebați din nou — următorul apel trece fără reconectare.',
	'mcp.agent_window_closed' => 'Adminer Desktop a încetat să răspundă — fereastra a fost probabil închisă. Deschideți-o din nou și întrebați din nou; nu trebuie reconectat nimic.',
	'mcp.agent_session_expired' => 'Sesiunea Adminer Desktop a expirat. Autentificați-vă din nou la baza de date în aplicație, apoi întrebați din nou.',
	'mcp.agent_not_connected' => 'Adminer Desktop nu este conectat la nicio bază de date. Autentificați-vă din nou în aplicație.',
	'mcp.agent_note_committed' => 'Scrierile sunt permise: tot ce a modificat această instrucțiune a fost confirmat.',
	'mcp.agent_note_rolled_back' => 'Aceasta a rulat într-o tranzacție anulată. Nu s-a scris nimic. Orice id returnat de RETURNING provine dintr-o secvență și nu identifică un rând stocat.',
	'mcp.agent_note_cut' => 'Valorile enumerate în truncated_cells au fost trunchiate pentru ca o singură coloană mare să nu îți umple contextul. Apelează din nou cu whole_values: true dacă ai nevoie de ele întregi.',
	'mcp.agent_note_no_database' => 'Fereastra nu are nicio bază de date deschisă, așa că instrumentele rulează în baza de date implicită a serverului, dacă are una. Pentru a interoga o anumită bază de date, transmiteți-o ca database: list_databases le enumeră.',
	'mcp.agent_unknown_connection' => 'Nu este o conexiune salvată autentificată. list_connections le enumeră; una neautentificată trebuie mai întâi deschisă în fereastra Adminer Desktop',
	'mcp.agent_forward_failed' => 'Adminer Desktop nu a putut răspunde la apel pe această conexiune. Nu conexiunea este problema: cauza se află în jurnalul aplicației',
	'mcp.agent_connect_failed' => 'Adminer Desktop nu s-a putut conecta cu această autentificare salvată: serverul ei nu rulează sau parola nu mai este valabilă. Adminer o deconectează atunci, așa că cereți utilizatorului să se autentifice din nou în fereastră după ce serverul pornește',
	'mcp.agent_no_database' => 'Baza de date nu a putut fi deschisă cu această autentificare',
	'mcp.agent_overprivileged' => 'Această autentificare depășește o interogare doar pentru citire — rulează un program pe serverul bazei de date, îi citește fișierele sau hash-urile parolelor — așa că Adminer Desktop nu o pune la dispoziția unui agent. Autentificați fereastra cu un rol fără aceste drepturi sau retrageți-le acestuia',
	'mcp.agent_privileges_unknown' => 'Adminer Desktop nu a putut verifica ce are voie această autentificare pe server, așa că nu o pune la dispoziția unui agent',
	'mcp.agent_no_result' => 'instrucțiunea nu a returnat niciun set de rezultate',
	'mcp.agent_parse_error' => 'eroare de analiză',
	'mcp.agent_unknown_method' => 'metodă necunoscută',
	'mcp.agent_unknown_tool' => 'unealtă necunoscută',
	'mcp.agent_missing_argument' => 'lipsește un argument obligatoriu',

	// Keyboard shortcuts tab (src/Shortcuts/).
	'shortcuts.group_app' => 'Aplicație',
	'shortcuts.group_forms' => 'Formulare și SQL',
	'shortcuts.help' => 'Afișează această listă',
	'shortcuts.reload' => 'Reîncarcă pagina',
	'shortcuts.close' => 'Închide un dialog sau un meniu',
	'shortcuts.adminer' => 'Comută la Adminer',
	'shortcuts.editor' => 'Comută la Editor',
	'shortcuts.logs' => 'Deschide jurnalele',
	'shortcuts.quit' => 'Ieșire',
	'shortcuts.submit' => 'Rulează SQL-ul sau salvează formularul',
	'shortcuts.insert_next' => 'Salvează și inserează rândul următor',
	'shortcuts.field_up' => 'Mergi la câmpul de deasupra',
	'shortcuts.field_down' => 'Mergi la câmpul de dedesubt',
	'shortcuts.search' => 'Caută tabele și baze de date',

	// Search everywhere, on Shift twice (src/Search/).
	'search.title' => 'Caută',
	'search.placeholder' => 'Scrie o parte din nume',
	'search.table' => 'tabel',
	'search.view' => 'vizualizare',
	'search.database' => 'bază de date',
	'search.schema' => 'schemă',
	'search.none' => 'Nicio potrivire',
];
