<?php
declare(strict_types=1);

// Plugin UI strings, German. IDs mirror en.php (the base); an ID left out here falls back to
// English, so `make i18n-check` lists what is still missing.

return [
	// The plugin's own description, shown in Adminer's plugin list (AdminerDesktop::description()).
	'plugin.description' => 'Passt die Standardwerte von Adminer an den Desktop-Betrieb an.',

	// Settings dialog shell.
	'settings.title' => 'Einstellungen',
	'settings.tab_theme' => 'Darstellung',
	'settings.tab_plugins' => 'Plugins',
	'settings.tab_shortcuts' => 'Tastenkürzel',
	'settings.save' => 'Speichern',
	'settings.close' => 'Schließen',
	'modal.close' => 'Schließen',
	'settings.reset' => 'Auf Standard zurücksetzen',
	'settings.reset_confirm' => 'Alle Einstellungen auf den Standard zurücksetzen? Design, Plugins und die von Ihnen angepassten Breiten gehen verloren. Adminers eigene Sprache und gespeicherte Server bleiben erhalten.',

	// Data list pager (src/Assets/javascript/table-pager.js): "1-20 of 50", the rows on screen
	// out of the rows there are.
	'js.pager.of' => 'von',

	// A long select narrowed by typing (src/Assets/javascript/select-filter.js).
	'js.select_filter.placeholder' => 'Teil eines Namens eingeben',
	'js.select_filter.none' => 'Keine Treffer',

	'busy.loading' => 'Wird geladen…',
	'busy.stop' => 'Stoppen',

	// Saved connections.
	'connections.new' => 'Neue Verbindung',
	'connections.switch' => 'Verbindung wechseln',
	'connections.cancel' => 'Abbrechen',
	'connections.stop_title' => 'Verbindungsaufbau abbrechen?',
	'connections.stop_text' => 'Der Server hat noch nicht geantwortet. Was Sie eingegeben haben, bleibt im Formular.',
	'connections.keep_waiting' => 'Weiter warten',
	'connections.stop' => 'Abbrechen',
	'connections.added' => 'Verbunden. Mit einem Namen ist die Verbindung leicht von den anderen zu unterscheiden.',
	'connections.empty' => 'Noch keine gespeicherten Verbindungen.',
	'connections.edit' => 'Bearbeiten',
	'connections.name' => 'Name',
	'connections.environment' => 'Umgebung',
	'connections.color' => 'Farbe',
	'connections.none' => 'Keine',
	'connections.logout' => 'Abmelden',
	'connections.logout_confirm' => 'Das Passwort wird vergessen. Die Verbindung bleibt in der Liste und fragt beim nächsten Mal danach.',
	'connections.forget' => 'Vergessen',
	'connections.forget_confirm' => 'Die Verbindung wird aus der Liste entfernt, mit Passwort, Name, Umgebung und Farbe.',
	'connections.signed_out' => 'abgemeldet',
	'connections.discard_title' => 'Änderungen verwerfen?',
	'connections.discard_text' => 'Was Sie hier geändert haben, geht verloren.',
	'connections.discard' => 'Verwerfen',
	'connections.keep_editing' => 'Weiter bearbeiten',

	// Theme panel.
	'theme.appearance' => 'Erscheinungsbild',
	'theme.appearance_hint' => 'Adminer Desktop folgt dem hellen und dunklen Modus des Systems, oder Sie legen ihn fest auf Hell oder Dunkel. Jeder Modus verwendet das unten gewählte Design.',
	'theme.appearance_auto' => 'Mit System synchronisieren',
	'theme.light' => 'Hell',
	'theme.dark' => 'Dunkel',
	'theme.language' => 'Sprache',
	'theme.density' => 'Zeilendichte',
	'theme.density_compact' => 'Kompakt',
	'theme.density_cozy' => 'Mittel',
	'theme.density_comfortable' => 'Luftig',
	'theme.scaling' => 'Skalierung',
	'theme.design' => 'Design',
	'theme.preview' => 'Vorschau',
	// Product name; the same on both sides, but carried here so coverage stays complete.
	'theme.builtin_design' => 'Adminer Desktop',

	// Plugins panel.
	'plugins.col_name' => 'Plugin',
	'plugins.col_desc' => 'Funktion',

	// Whole-page import dropzone.
	'js.import.drop_hint' => 'SQL-Datei zum Importieren hier ablegen',
	'js.sidebar.toggle' => 'Seitenleiste ein- oder ausblenden',
	'js.toolbar.search' => 'Suchen',
	'js.history.back' => 'Zurück',
	'js.history.forward' => 'Vorwärts',
	'js.pathbar.label' => 'Pfad',
	'js.sort.none' => 'Keine',

	// Letting an agent query the open database (Mcp\Panel, mcp-panel.latte).
	'settings.tab_mcp' => 'KI-Zugriff',
	'mcp.enable' => 'Einem KI-Agenten erlauben, diese Datenbank abzufragen',
	'mcp.description' => 'Ein unten registrierter Agent kann die gespeicherten Verbindungen lesen, bei denen Sie angemeldet sind — die dieses Fensters oder eine andere, die er selbst wählt — die Struktur und Abfragen, um die Sie ihn bitten. Schreiben kann er nicht: Jede Abfrage läuft in einer Transaktion, die zurückgerollt wird. Der Zugriff besteht nur, solange dieses Fenster geöffnet und angemeldet ist.',
	'mcp.status_off' => 'Aus. Nichts erreicht die Datenbank.',
	'mcp.status_waiting' => 'An, aber noch nicht erreichbar — melden Sie sich an einer Datenbank an, dann kann der Agent sie abfragen.',
	'mcp.status_ready' => 'Bereit. Ein registrierter Agent kann die Datenbank abfragen, in der Sie angemeldet sind.',
	'mcp.register_on' => 'Beim Agenten registrieren unter',
	'mcp.register_hint' => 'Kopieren und einmal im Terminal ausführen. Funktioniert auch nach einem Neustart.',
	'mcp.register_add' => 'Hinzufügen',
	'mcp.register_remove' => 'Entfernen',
	'mcp.remove_hint' => 'Ausführen, wenn der Agent die App nicht mehr starten soll, oder vor dem erneuten Hinzufügen, wenn die App verschoben wurde: Die alte Registrierung zeigt weiter auf den alten Pfad, und der Agent meldet nur, sie sei getrennt.',
	'mcp.readonly_note' => 'Ein Rollback macht Datenänderungen rückgängig. Manche Datenbanken bestätigen eine Strukturänderung sofort, dort lässt sie sich nicht zurücknehmen. Damit Schreibzugriffe unmöglich statt rückgängig gemacht werden, melden Sie sich als schreibgeschützter Datenbankbenutzer an.',
	'mcp.whole_enable' => 'Lange Werte immer vollständig zurückgeben',
	'mcp.whole_note' => 'Werte über 2000 Zeichen werden sonst gekürzt, damit eine große Spalte nicht den Kontext des Agenten füllt. Der Agent kann vollständige Werte trotzdem pro Abfrage anfordern.',
	'mcp.used_moments' => 'Letzte Abfrage: gerade eben.',
	'mcp.used_minutes' => 'Letzte Abfrage: innerhalb der letzten Stunde.',
	'mcp.used_hours' => 'Letzte Abfrage: heute.',
	'mcp.used_days' => 'Letzte Abfrage: vor über einem Tag.',
	'mcp.copy' => 'Kopieren',
	'mcp.copied' => 'Kopiert',
	'mcp.target' => 'Der Agent würde abfragen:',
	'mcp.write_enable' => 'Daten dauerhaft ändern und löschen lassen (INSERT, UPDATE, DELETE)',
	'mcp.write_on' => 'Schreibvorgänge werden bestätigt, außer bei einer als Prod markierten Verbindung, die nur lesbar bleibt. Der Agent wählt seine Anweisungen selbst, und ein falsches DELETE ist von hier aus nicht wiederherstellbar.',
	'mcp.write_off' => 'Nur Lesen. Anweisungen, die schreiben würden, werden ausgeführt und dann zurückgerollt, haben also keine Wirkung.',

	'mcp.register_manual' => 'Jeder andere Agent funktioniert ebenfalls: Tragen Sie denselben Befehl als stdio-MCP-Server in seine eigene Konfigurationsdatei ein.',

	// Was der Agent erfährt, nicht was das Panel zeigt (Mcp\Stdio, Server, Tools, Endpoint).
	// Ein Mensch liest es über den Agenten, deshalb übersetzt; die Namen der Werkzeuge und
	// ihre Beschreibungen nicht — siehe Desktop\I18n\Strings.
	'mcp.agent_not_running' => 'Adminer Desktop läuft nicht, oder der Datenbankzugriff für Agenten ist in den Einstellungen abgeschaltet. Öffnen Sie die App, melden Sie sich an und schalten Sie ihn unter Einstellungen > KI-Zugriff ein, dann fragen Sie erneut — der nächste Aufruf geht ohne neues Verbinden durch.',
	'mcp.agent_window_closed' => 'Adminer Desktop antwortet nicht mehr — das Fenster wurde vermutlich geschlossen. Öffnen Sie es erneut und fragen Sie noch einmal; nichts muss neu verbunden werden.',
	'mcp.agent_timed_out' => 'Adminer Desktop hat länger als eine Minute nicht geantwortet, daher wurde der Aufruf abgebrochen. War es ein Schreibvorgang, wurde er womöglich trotzdem ausgeführt — prüfen Sie das, bevor Sie ihn erneut ausführen.',
	'mcp.agent_statement_too_long' => 'Die Anweisung lief länger als {n} Sekunden, daher hat die Datenbank sie abgebrochen; bei einer transaktionalen Tabelle wurde keine ihrer Änderungen übernommen. Schränken Sie sie ein (ein WHERE, ein LIMIT, ein Index) oder bitten Sie den Benutzer, sie im Fenster von Adminer Desktop auszuführen, wo es kein Limit gibt.',
	'mcp.agent_session_expired' => 'Die Sitzung von Adminer Desktop ist abgelaufen. Melden Sie sich in der App erneut an der Datenbank an und fragen Sie dann noch einmal.',
	'mcp.agent_not_connected' => 'Adminer Desktop ist mit keiner Datenbank verbunden. Melden Sie sich in der App erneut an.',
	'mcp.agent_note_committed' => 'Schreibzugriffe sind erlaubt: Was diese Anweisung geändert hat, wurde festgeschrieben.',
	'mcp.agent_note_rolled_back' => 'Dies lief in einer Transaktion, die zurückgerollt wurde. Es wurde nichts geschrieben. Eine von RETURNING gelieferte id stammt aus einer Sequenz und bezeichnet keine gespeicherte Zeile.',
	'mcp.agent_note_cut' => 'Die in truncated_cells aufgeführten Werte wurden gekürzt, damit eine große Spalte nicht Ihren Kontext füllt. Rufen Sie erneut mit whole_values: true auf, wenn Sie sie vollständig brauchen.',
	'mcp.agent_note_no_database' => 'Im Fenster ist keine Datenbank geöffnet, daher laufen die Werkzeuge in der Standarddatenbank des Servers, sofern er eine hat. Um eine bestimmte Datenbank abzufragen, übergeben Sie sie als database: list_databases nennt sie.',
	'mcp.agent_unknown_connection' => 'Keine angemeldete gespeicherte Verbindung. list_connections nennt sie; eine nicht angemeldete muss zuerst im Fenster von Adminer Desktop geöffnet werden',
	'mcp.agent_forward_failed' => 'Adminer Desktop konnte den Aufruf auf dieser Verbindung nicht beantworten. An der Verbindung liegt es nicht: Die Ursache steht im Log der App',
	'mcp.agent_connect_failed' => 'Adminer Desktop konnte sich mit dieser gespeicherten Anmeldung nicht verbinden: deren Server läuft nicht, oder das Passwort gilt nicht mehr. Adminer meldet sie dann ab, also bitten Sie den Nutzer, sich im Fenster erneut anzumelden, sobald der Server läuft',
	'mcp.agent_no_database' => 'Die Datenbank konnte mit dieser Anmeldung nicht geöffnet werden',
	'mcp.agent_overprivileged' => 'Diese Anmeldung reicht über eine schreibgeschützte Abfrage hinaus — sie startet ein Programm auf dem Datenbankserver, liest seine Dateien oder die Passwort-Hashes —, daher überlässt Adminer Desktop sie keinem Agenten. Melden Sie das Fenster mit einer Rolle ohne diese Rechte an oder entziehen Sie sie dieser',
	'mcp.agent_privileges_unknown' => 'Adminer Desktop konnte nicht prüfen, was diese Anmeldung auf dem Server darf, daher überlässt es sie keinem Agenten',
	'mcp.agent_no_result' => 'die Anweisung lieferte keine Ergebnismenge',
	'mcp.agent_parse_error' => 'Parse-Fehler',
	'mcp.agent_unknown_method' => 'unbekannte Methode',
	'mcp.agent_unknown_tool' => 'unbekanntes Werkzeug',
	'mcp.agent_missing_argument' => 'erforderliches Argument fehlt',

	// Keyboard shortcuts tab (src/Shortcuts/).
	'shortcuts.group_app' => 'App',
	'shortcuts.group_forms' => 'Formulare und SQL',
	'shortcuts.help' => 'Diese Liste anzeigen',
	'shortcuts.settings' => 'Einstellungen öffnen',
	'shortcuts.reload' => 'Seite neu laden',
	'shortcuts.close' => 'Dialog oder Menü schließen',
	'shortcuts.adminer' => 'Zu Adminer wechseln',
	'shortcuts.editor' => 'Zu Editor wechseln',
	'shortcuts.logs' => 'Logs öffnen',
	'shortcuts.quit' => 'Beenden',
	'shortcuts.submit' => 'SQL ausführen oder Formular speichern',
	'shortcuts.insert_next' => 'Speichern und nächste Zeile einfügen',
	'shortcuts.field_up' => 'Zum Feld darüber',
	'shortcuts.field_down' => 'Zum Feld darunter',
	'shortcuts.search' => 'Tabellen und Datenbanken suchen',

	// Search everywhere, on Shift twice (src/Search/).
	'search.title' => 'Suchen',
	'search.placeholder' => 'Teil eines Namens eingeben',
	'search.table' => 'Tabelle',
	'search.view' => 'Ansicht',
	'search.database' => 'Datenbank',
	'search.schema' => 'Schema',
	'search.none' => 'Keine Treffer',
];
