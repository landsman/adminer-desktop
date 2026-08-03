# The 79 hooks on `Adminer\Adminer`

Every method below can be overridden by a plugin. Extracted from the pinned
`app/adminer.php` (Adminer 5.5.1) by tokenising the class.

**Arity and defaults are exact. Parameter *names* are reconstructed** — the compiled
adminer.php has them minified (`$Ti`, `$Ob`, `$Kb`…), so names here are inferred from
how the upstream plugins in `app/src/Settings/Plugins/available/` call and override
them. Trust the count and the defaults; treat a name as a hint.

Dispatch rules (see `../SKILL.md`): `return null` to abstain and let the next plugin
answer; the **first non-null return wins and stops the chain**; arguments arrive **by
reference**; and only the five hooks marked **+** accumulate rather than
short-circuiting.

The **n** column is how many of the ~50 upstream plugins override it — a decent proxy
for how well-trodden the seam is.

## Connection, identity and login

| Hook | n | Purpose |
|---|--:|---|
| `name()` | | The application name shown in the page header. |
| `credentials()` | 4 | Return `[server, username, password]` to supply the connection programmatically instead of from the login form. The seam used by `login-password-less`, `login-servers`, `login-ssl`. |
| `connectSsl()` | 1 | SSL options for the connection. |
| `permanentLogin($create = false)` | | Return the key used to encrypt the "Permanent login" cookie. `AdminerDesktop` overrides this to keep the key in the durable data dir. |
| `bruteForceKey()` | 1 | The key brute-force protection counts against (default: IP). |
| `serverName($server)` | | Display name for a server. |
| `database()` | 1 | The database to use when none is selected. |
| `databases($flush = true)` | 1 | The list of databases to offer. |
| `schemas()` | | The list of schemas to offer. |
| `queryTimeout()` | | Timeout in seconds applied to `SELECT` queries. |
| `login($login, $password)` | 6 | Authorise or reject a login. Return `true` to accept, a string to reject with a message. |
| `loginForm()` | | Print the whole login form. |
| `loginFormField($name, $heading, $value)` | 3 | Replace the markup of one login-form field. Return `null` for the fields you do not care about — see `AdminerDesktop::loginFormField()`. |
| `afterConnect()` | 2 | Runs once the connection is up. **Never fires on the login page**, so it is the wrong place for anything that must work before login. |

## Page shell and assets

| Hook | n | Purpose |
|---|--:|---|
| `headers()` | 3 | Fires early, before adminer produces a page. Send headers here — or intercept the request entirely: write your own body and `exit` (the `sql-gemini.php` pattern). |
| `csp(array $csp)` | 1 | Adjust the Content-Security-Policy array. |
| `head($dark = null)` | 7 | Emit tags into `<head>`. The most common place to add scripts and styles. |
| `bodyClass()` | | Extra classes on `<body>`. `AdminerDesktop` drives the `theme-*`, `os-*` and `density-*` hooks from here. |
| `css()` | 2 | Return a list of stylesheet URLs. |
| `pluginsLinks()` | 1 | Links shown in the plugins section of the menu. |
| `navigation($missing)` | 2 | Print the whole left-hand navigation. |
| `homepage()` | | Print the homepage body. |
| `syntaxHighlighting(array $tables)` | 3 | Swap the SQL syntax highlighter (CodeMirror / Monaco / Prism plugins use this). |

## Names and value rendering

| Hook | n | Purpose |
|---|--:|---|
| `tableName(array $tableStatus)` | 2 | The displayed name of a table. Return `''` to hide it from the navigation. |
| `fieldName(array $field, $order = 0)` | 1 | The displayed name of a column. |
| `rowDescription($table)` | | A description query for rows of a table. |
| `rowDescriptions(array $rows, array $foreignKeys)` | | Descriptions for a set of rows. |
| `selectLink($value, array $field)` | | Link for a value in the select results. |
| `selectVal($link, $value, array $field, $original)` | 2 | The rendered value of one cell in the select results. |
| `editVal($value, array $field)` | 1 | The value put into the edit form. |
| `operators()` | | The comparison operators offered in search. |

## The select page

`*Print` hooks render the control; the matching `*Process` hook reads it back off the
request.

| Hook | n | Purpose |
|---|--:|---|
| `selectLinks(array $tableStatus, $set = "")` | | The table-level action links. |
| `selectQuery($query, $start, $failed = false)` | | Wrap or annotate the printed SQL of the select. |
| `selectQueryBuild(array $select, array $where, array $group, array $order, $limit, $page)` | | Build the query yourself; return non-null to replace adminer's. |
| `selectColumnsPrint(array $select, array $columns)` | | |
| `selectColumnsProcess(array $columns, array $indexes)` | | |
| `selectSearchPrint(array $where, array $columns, array $indexes)` | | |
| `selectSearchProcess(array $fields, array $indexes)` | | |
| `selectOrderPrint(array $order, array $columns, array $indexes)` | | |
| `selectOrderProcess(array $fields, array $indexes)` | | |
| `selectLimitPrint($limit)` | | |
| `selectLimitProcess()` | | |
| `selectLengthPrint($textLength)` | | |
| `selectLengthProcess()` | | |
| `selectActionPrint(array $indexes)` | | |
| `selectCommandPrint()` | | Whether to offer the SQL command link. |
| `selectImportPrint()` | | Whether to offer the import link. |
| `selectEmailPrint(array $emailFields, array $columns)` | 2 | |
| `selectEmailProcess(array $where, array $foreignKeys)` | 2 | |

## Keys and structure

| Hook | n | Purpose |
|---|--:|---|
| `foreignKeys($table)` | 1 | The foreign keys of a table. |
| `backwardKeys($table, $tableName)` | 2 | Tables referencing this one. |
| `backwardKeysPrint(array $backwardKeys, array $row)` | 2 | Render those links. |
| `tableStructurePrint(array $fields, $tableStatus = null)` | 1 | Print the structure table. |
| `tableIndexesPrint(array $indexes, array $tableStatus)` | 1 | Print the indexes table. |

## Editing rows

| Hook | n | Purpose |
|---|--:|---|
| `editRowPrint($table, array $fields, $row, $update)` **+** | | Accumulating. |
| `editFunctions(array $field)` **+** | | Accumulating — the function `<select>` beside an edit input. |
| `editInput($table, array $field, $attrs, $value)` | 9 | The single most-overridden hook: replace the input widget for a column. |
| `editHint($table, array $field, $value)` | | Hint shown under an input. |
| `processInput(array $field, $value, $function = "")` | 2 | Transform a submitted value before it reaches the database. |

## SQL command

| Hook | n | Purpose |
|---|--:|---|
| `sqlCommandQuery($query)` | 1 | Pre-fill or rewrite the SQL command textarea. |
| `sqlPrintAfter()` | 1 | Print extra markup after the SQL command form. |
| `messageQuery($query, $time, $failed = false)` | 2 | The "SQL executed" message and its detail. |

## Export and import

| Hook | n | Purpose |
|---|--:|---|
| `dumpOutput()` **+** | 2 | Accumulating — output targets (file, gzip, …). |
| `dumpFormat()` **+** | 4 | Accumulating — formats offered (SQL, CSV, JSON, XML, PHP…). |
| `dumpDatabase($db)` | 1 | Emit the database-level part of a dump. |
| `dumpTable($table, $style, $isView = 0)` | 4 | Emit a table's structure. |
| `dumpData($table, $style, $query)` | 4 | Emit a table's rows. |
| `dumpFilename($identifier)` | 1 | Name of the downloaded file. |
| `dumpHeaders($identifier, $multipleTables = false)` | 5 | Content type and encoding; return the file extension. |
| `dumpFooter()` | 4 | Emit anything trailing. |
| `importServerPath()` | | Path offered for server-side import. |

## Server administration

| Hook | n | Purpose |
|---|--:|---|
| `databasesPrint($missing)` | | Print the database selector. |
| `menuActions(array $actions, $missing)` | | Adjust the menu's action links. |
| `tablesPrint(array $tables)` | 2 | Print the table list in the navigation. |
| `showVariables()` | | Replace the variables listing. |
| `showStatus()` | | Replace the status listing. |
| `processList()` | | Replace the process listing. |
| `killProcess($id)` | | The query used to kill a process. |

## Everything else

| Hook | n | Purpose |
|---|--:|---|
| `config()` **+** | 3 | Accumulating — plugin configuration merged across plugins. |

## From `Adminer\Plugin` (the base class you extend)

Only three methods, and they are not hooks in the dispatch sense — adminer calls them
on your instance directly:

| Method | Purpose |
|---|---|
| `description()` | Returns `$translations[LANG]['']` — the plugin's own description in the settings UI. |
| `screenshot()` | URL of a screenshot shown beside the description. |
| `lang($message, $number = null)` | Translate via the plugin's `protected $translations`. **Runs through sprintf** — use `{n}`, not `%d`, for anything destined for JavaScript. |
