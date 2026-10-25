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
	'settings.tab_shortcuts' => 'Skróty',
	'settings.save' => 'Zapisz',
	'settings.close' => 'Zamknij',
	'modal.close' => 'Zamknij',
	'settings.reset' => 'Przywróć domyślne',
	'settings.reset_confirm' => 'Przywrócić wszystkie ustawienia do domyślnych? Wygląd, wtyczki i zmienione przez Ciebie szerokości zostaną zapomniane. Język i zapisane serwery Adminera pozostaną.',

	// Data list pager (src/Assets/javascript/table-pager.js): "1-20 of 50", the rows on screen
	// out of the rows there are.
	'js.pager.of' => 'z',

	// A long select narrowed by typing (src/Assets/javascript/select-filter.js).
	'js.select_filter.placeholder' => 'Wpisz część nazwy',
	'js.select_filter.none' => 'Brak wyników',

	'busy.loading' => 'Ładowanie…',

	// Saved connections.
	'connections.new' => 'Nowe połączenie',
	'connections.switch' => 'Zmień połączenie',
	'connections.cancel' => 'Anuluj',
	'connections.stop_title' => 'Przerwać łączenie?',
	'connections.stop_text' => 'Serwer jeszcze nie odpowiedział. To, co wpisano, zostanie w formularzu.',
	'connections.keep_waiting' => 'Czekaj dalej',
	'connections.stop' => 'Przerwij',
	'connections.added' => 'Połączono. Nazwa pozwoli łatwo odróżnić to połączenie od pozostałych.',
	'connections.empty' => 'Brak zapisanych połączeń.',
	'connections.edit' => 'Edytuj',
	'connections.name' => 'Nazwa',
	'connections.environment' => 'Środowisko',
	'connections.color' => 'Kolor',
	'connections.none' => 'Brak',
	'connections.logout' => 'Wyloguj',
	'connections.logout_confirm' => 'Hasło zostanie zapomniane. Połączenie zostaje na liście i następnym razem o nie zapyta.',
	'connections.forget' => 'Zapomnij',
	'connections.forget_confirm' => 'Połączenie zniknie z listy wraz z hasłem, nazwą, środowiskiem i kolorem.',
	'connections.signed_out' => 'wylogowano',
	'connections.discard_title' => 'Odrzucić zmiany?',
	'connections.discard_text' => 'To, co tu zmieniono, zostanie utracone.',
	'connections.discard' => 'Odrzuć',
	'connections.keep_editing' => 'Edytuj dalej',

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
	'js.import.drop_hint' => 'Upuść tutaj plik SQL, aby go zaimportować',
	'js.sidebar.toggle' => 'Pokaż lub ukryj pasek boczny',
	'js.toolbar.search' => 'Szukaj',
	'js.history.back' => 'Wstecz',
	'js.history.forward' => 'Dalej',
	'js.pathbar.label' => 'Ścieżka',
	'js.sort.none' => 'Brak',

	// Letting an agent query the open database (Mcp\Panel, mcp-panel.latte).
	'settings.tab_mcp' => 'Dostęp AI',
	'mcp.enable' => 'Pozwól agentowi AI odpytywać tę bazę danych',
	'mcp.description' => 'Zarejestrowany poniżej agent może czytać zapisane połączenia, do których jesteś zalogowany — to, na którym jest to okno, albo inne, które sam wybierze — strukturę oraz zapytania, o które go poprosisz. Nie może zapisywać: każde zapytanie działa w transakcji, która jest wycofywana. Dostęp trwa tylko wtedy, gdy to okno jest otwarte i zalogowane.',
	'mcp.status_off' => 'Wyłączone. Nic nie dosięgnie bazy danych.',
	'mcp.status_waiting' => 'Włączone, ale jeszcze niedostępne — zaloguj się do bazy danych, a agent będzie mógł ją odpytywać.',
	'mcp.status_ready' => 'Gotowe. Zarejestrowany agent może odpytywać bazę danych, do której jesteś zalogowany.',
	'mcp.register_on' => 'Zarejestruj u swojego agenta w',
	'mcp.register_hint' => 'Skopiuj i uruchom raz w terminalu. Działa również po ponownym uruchomieniu.',
	'mcp.register_add' => 'Dodaj',
	'mcp.register_remove' => 'Usuń',
	'mcp.remove_hint' => 'Uruchom, gdy agent nie ma już uruchamiać aplikacji, albo przed ponownym dodaniem, jeśli aplikacja została przeniesiona: stara rejestracja wciąż wskazuje starą ścieżkę, a agent zgłasza tylko, że jest rozłączona.',
	'mcp.readonly_note' => 'Wycofanie transakcji cofa zmiany danych. Niektóre bazy zatwierdzają zmianę struktury natychmiast, więc tam nie da się jej cofnąć. Aby zapis był niemożliwy, a nie tylko cofany, zaloguj się jako użytkownik bazy tylko do odczytu.',
	'mcp.whole_enable' => 'Zawsze zwracaj długie wartości w całości',
	'mcp.whole_note' => 'Wartości dłuższe niż 2000 znaków są w przeciwnym razie obcinane, aby jedna duża kolumna nie zapełniła kontekstu agenta. Agent nadal może poprosić o pełne wartości dla pojedynczego zapytania.',
	'mcp.used_moments' => 'Ostatnie zapytanie: przed chwilą.',
	'mcp.used_minutes' => 'Ostatnie zapytanie: w ciągu ostatniej godziny.',
	'mcp.used_hours' => 'Ostatnie zapytanie: dzisiaj.',
	'mcp.used_days' => 'Ostatnie zapytanie: ponad dobę temu.',
	'mcp.copy' => 'Kopiuj',
	'mcp.copied' => 'Skopiowano',
	'mcp.target' => 'Agent odpytywałby:',
	'mcp.write_enable' => 'Zezwól na trwałą zmianę i usuwanie danych (INSERT, UPDATE, DELETE)',
	'mcp.write_on' => 'Zapisy są zatwierdzane, z wyjątkiem połączenia oznaczonego jako Prod, które pozostaje tylko do odczytu. Agent sam dobiera polecenia, a błędnego DELETE nie da się stąd cofnąć.',
	'mcp.write_off' => 'Tylko odczyt. Polecenia, które zapisywałyby dane, są wykonywane i wycofywane, więc nie mają efektu.',

	'mcp.register_manual' => 'Każdy inny agent też zadziała: podaj mu to samo polecenie jako serwer MCP na stdio w jego własnym pliku konfiguracyjnym.',

	// Co dostaje agent, a nie co pokazuje panel (Mcp\Stdio, Server, Tools, Endpoint). Czyta to
	// człowiek za pośrednictwem agenta, dlatego jest tłumaczone; nazwy narzędzi i ich opisy
	// nie — zob. Desktop\I18n\Strings.
	'mcp.agent_not_running' => 'Adminer Desktop nie działa albo dostęp agentów do bazy jest wyłączony w jego ustawieniach. Otwórz aplikację, zaloguj się i włącz go w Ustawienia > Dostęp AI, a potem zapytaj ponownie — następne wywołanie przejdzie bez ponownego łączenia.',
	'mcp.agent_window_closed' => 'Adminer Desktop przestał odpowiadać — okno zostało prawdopodobnie zamknięte. Otwórz je ponownie i zapytaj jeszcze raz; nie trzeba niczego łączyć ponownie.',
	'mcp.agent_timed_out' => 'Adminer Desktop nie odpowiedział w ciągu minuty, więc wywołanie zostało przerwane. Jeśli był to zapis, mógł mimo to zostać wykonany — sprawdź to, zanim uruchomisz go ponownie.',
	'mcp.agent_statement_too_long' => 'Polecenie działało dłużej niż {n} sekund, więc baza danych je przerwała; w tabeli transakcyjnej żadna z jego zmian nie została zachowana. Zawęź je (WHERE, LIMIT, indeks) albo poproś użytkownika, by uruchomił je w oknie Adminer Desktop, gdzie nie ma limitu.',
	'mcp.agent_session_expired' => 'Sesja Adminer Desktop wygasła. Zaloguj się w aplikacji do bazy ponownie, a potem zapytaj jeszcze raz.',
	'mcp.agent_not_connected' => 'Adminer Desktop nie jest połączony z bazą danych. Zaloguj się ponownie w aplikacji.',
	'mcp.agent_note_committed' => 'Zapisy są włączone: wszystko, co zmieniła ta instrukcja, zostało zatwierdzone.',
	'mcp.agent_note_rolled_back' => 'To wykonało się w transakcji, która została wycofana. Nic nie zostało zapisane. Identyfikator zwrócony przez RETURNING pochodzi z sekwencji i nie wskazuje zapisanego wiersza.',
	'mcp.agent_note_cut' => 'Wartości wymienione w truncated_cells zostały obcięte, aby jedna duża kolumna nie zapełniła kontekstu. Jeśli potrzebujesz ich w całości, wywołaj ponownie z whole_values: true.',
	'mcp.agent_note_no_database' => 'W oknie nie jest otwarta żadna baza danych, więc narzędzia działają w domyślnej bazie serwera, jeśli taką ma. Aby odpytać konkretną bazę, przekaż ją jako database: wymienia je list_databases.',
	'mcp.agent_unknown_connection' => 'To nie jest zalogowane zapisane połączenie. Wymienia je list_connections; niezalogowane trzeba najpierw otworzyć w oknie Adminer Desktop',
	'mcp.agent_forward_failed' => 'Adminer Desktop nie obsłużył wywołania na tym połączeniu. Problem nie leży w połączeniu: przyczyna jest w logu aplikacji',
	'mcp.agent_connect_failed' => 'Adminer Desktop nie połączył się z tym zapisanym logowaniem: jego serwer nie działa albo hasło jest już nieważne. Adminer wtedy je wylogowuje, więc poproś użytkownika, by zalogował się do niego ponownie w oknie, gdy serwer zacznie działać',
	'mcp.agent_no_database' => 'Nie udało się otworzyć bazy danych przy tym logowaniu',
	'mcp.agent_overprivileged' => 'To logowanie wykracza poza zapytanie tylko do odczytu — uruchomi program na serwerze bazy danych, odczyta jego pliki lub skróty haseł — dlatego Adminer Desktop nie udostępni go agentowi. Zaloguj okno rolą bez tych uprawnień albo odbierz je tej roli',
	'mcp.agent_privileges_unknown' => 'Adminer Desktop nie mógł sprawdzić, co to logowanie może na serwerze, dlatego nie udostępni go agentowi',
	'mcp.agent_no_result' => 'instrukcja nie zwróciła zbioru wyników',
	'mcp.agent_parse_error' => 'błąd parsowania',
	'mcp.agent_unknown_method' => 'nieznana metoda',
	'mcp.agent_unknown_tool' => 'nieznane narzędzie',
	'mcp.agent_missing_argument' => 'brak wymaganego argumentu',

	// Keyboard shortcuts tab (src/Shortcuts/).
	'shortcuts.group_app' => 'Aplikacja',
	'shortcuts.group_forms' => 'Formularze i SQL',
	'shortcuts.help' => 'Pokaż tę listę',
	'shortcuts.reload' => 'Odśwież stronę',
	'shortcuts.close' => 'Zamknij okno dialogowe lub menu',
	'shortcuts.adminer' => 'Przełącz na Adminer',
	'shortcuts.editor' => 'Przełącz na Editor',
	'shortcuts.logs' => 'Otwórz logi',
	'shortcuts.quit' => 'Zakończ',
	'shortcuts.submit' => 'Uruchom SQL lub zapisz formularz',
	'shortcuts.insert_next' => 'Zapisz i wstaw następny wiersz',
	'shortcuts.field_up' => 'Przejdź do pola wyżej',
	'shortcuts.field_down' => 'Przejdź do pola niżej',
	'shortcuts.search' => 'Szukaj tabel i baz danych',

	// Search everywhere, on Shift twice (src/Search/).
	'search.title' => 'Szukaj',
	'search.placeholder' => 'Wpisz część nazwy',
	'search.table' => 'tabela',
	'search.view' => 'widok',
	'search.database' => 'baza danych',
	'search.schema' => 'schemat',
	'search.none' => 'Brak wyników',
];
