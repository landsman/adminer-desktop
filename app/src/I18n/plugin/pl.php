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

	// Letting an agent query the open database (Mcp\Panel, mcp-panel.latte).
	'settings.tab_mcp' => 'Dostęp AI',
	'mcp.enable' => 'Pozwól agentowi AI odpytywać tę bazę danych',
	'mcp.description' => 'Zarejestrowany poniżej agent może czytać bazę danych, do której zalogowane jest to okno — strukturę oraz zapytania, o które go poprosisz. Nie może zapisywać: każde zapytanie działa w transakcji, która jest wycofywana. Dostęp trwa tylko wtedy, gdy to okno jest otwarte i zalogowane.',
	'mcp.status_off' => 'Wyłączone. Nic nie dosięgnie bazy danych.',
	'mcp.status_waiting' => 'Włączone, ale jeszcze niedostępne — zaloguj się do bazy danych, a agent będzie mógł ją odpytywać.',
	'mcp.status_ready' => 'Gotowe. Zarejestrowany agent może odpytywać bazę danych, do której jesteś zalogowany — połącz go ponownie, jeśli już działał.',
	'mcp.register_on' => 'Zarejestruj u swojego agenta w',
	'mcp.register_hint' => 'Skopiuj i uruchom raz w terminalu. Działa również po ponownym uruchomieniu.',
	'mcp.readonly_note' => 'Wycofanie transakcji cofa zmiany danych. Niektóre bazy zatwierdzają zmianę struktury natychmiast, więc tam nie da się jej cofnąć. Aby zapis był niemożliwy, a nie tylko cofany, zaloguj się jako użytkownik bazy tylko do odczytu.',
	'mcp.used_moments' => 'Ostatnie zapytanie: przed chwilą.',
	'mcp.used_minutes' => 'Ostatnie zapytanie: w ciągu ostatniej godziny.',
	'mcp.used_hours' => 'Ostatnie zapytanie: dzisiaj.',
	'mcp.used_days' => 'Ostatnie zapytanie: ponad dobę temu.',
	'mcp.copy' => 'Kopiuj',
	'mcp.copied' => 'Skopiowano',
	'mcp.target' => 'Agent odpytywałby:',
	'mcp.write_enable' => 'Zezwól na trwałą zmianę i usuwanie danych (INSERT, UPDATE, DELETE)',
	'mcp.write_on' => 'Zapisy są zatwierdzane. Agent sam dobiera polecenia, a błędnego DELETE nie da się stąd cofnąć.',
	'mcp.write_off' => 'Tylko odczyt. Polecenia, które zapisywałyby dane, są wykonywane i wycofywane, więc nie mają efektu.',

	'mcp.register_manual' => 'Każdy inny agent też zadziała: podaj mu to samo polecenie jako serwer MCP na stdio w jego własnym pliku konfiguracyjnym.',

	// Co dostaje agent, a nie co pokazuje panel (Mcp\Stdio, Server, Tools, Endpoint). Czyta to
	// człowiek za pośrednictwem agenta, dlatego jest tłumaczone; nazwy narzędzi i ich opisy
	// nie — zob. Desktop\I18n\Strings.
	'mcp.agent_not_running' => 'Adminer Desktop nie działa albo dostęp agentów do bazy jest wyłączony w jego ustawieniach. Otwórz aplikację, zaloguj się i włącz go w Ustawienia > Dostęp AI — potem połącz ten serwer ponownie, ponieważ lista narzędzi jest odczytywana raz, przy otwieraniu połączenia.',
	'mcp.agent_window_closed' => 'Adminer Desktop przestał odpowiadać — okno zostało prawdopodobnie zamknięte. Otwórz je ponownie i połącz ten serwer jeszcze raz; sama rejestracja działa dalej.',
	'mcp.agent_session_expired' => 'Sesja Adminer Desktop wygasła. Zaloguj się w aplikacji do bazy ponownie, a następnie połącz ten serwer jeszcze raz.',
	'mcp.agent_not_connected' => 'Adminer Desktop nie jest połączony z bazą danych. Zaloguj się ponownie w aplikacji.',
	'mcp.agent_note_committed' => 'Zapisy są włączone: wszystko, co zmieniła ta instrukcja, zostało zatwierdzone.',
	'mcp.agent_note_rolled_back' => 'To wykonało się w transakcji, która została wycofana. Nic nie zostało zapisane. Identyfikator zwrócony przez RETURNING pochodzi z sekwencji i nie wskazuje zapisanego wiersza.',
	'mcp.agent_no_result' => 'instrukcja nie zwróciła zbioru wyników',
	'mcp.agent_parse_error' => 'błąd parsowania',
	'mcp.agent_unknown_method' => 'nieznana metoda',
	'mcp.agent_unknown_tool' => 'nieznane narzędzie',
	'mcp.agent_missing_argument' => 'brak wymaganego argumentu',
];
