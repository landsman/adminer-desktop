# The `Adminer\` function surface

Functions plugins actually call, ranked and grouped. Signatures were tokenised out of
the pinned `app/adminer.php` (Adminer 5.5.1); **arity and defaults are exact,
parameter names are reconstructed** from usage in `app/src/Settings/Plugins/available/`.

The **n** column counts call sites across the ~50 upstream plugins — a decent signal
for what is idiomatic.

Everything is in the `Adminer\` namespace. From a plugin class in the global namespace
(`AdminerDesktop`) or in `Desktop\`, call them fully qualified: `Adminer\h($s)`.

## Escaping — get these right or you have an injection

| Function | n | Notes |
|---|--:|---|
| `h($string)` | 34 | HTML-escape. Adminer's own markup uses this everywhere. Our Latte templates escape by context instead, so `h()` is for code that emits adminer-shaped HTML directly. |
| `q($value)` | 25 | **Quote a value for SQL** — returns the value *including* quotes. This is the parameterisation adminer has; there is no bind-parameter API. |
| `idf_escape($identifier)` | 8 | Quote an identifier (table/column name). **Driver-dependent** — 5 definitions, one per driver. |
| `js_escape($string)` | 3 | Escape for a JavaScript string literal. |
| `bracket_escape($name, $decode = false)` | 1 | Escape/unescape a name for use in an HTML form field name. |

Anything interpolated into SQL goes through `q()` for values and `idf_escape()` for
identifiers — never string concatenation of raw input.

## Running queries

| Function | n | Notes |
|---|--:|---|
| `connection($connection = null)` | 10 | The live `Db` object. `Adminer\connection()->query($sql)` is the raw path. |
| `get_rows($query, $connection = null, $error = "<p class='error'>")` | 4 | Run a query, return all rows as an array of associative arrays. The workhorse. |
| `get_val($query, $field = 0, $connection = null)` | 2 | One scalar. |
| `get_vals($query, $column = 0)` | 1 | One column as a flat array. |
| `get_key_vals($query, $connection = null, $setKeys = true)` | 2 | Two columns as `key => value`. |
| `json_row($key, $value = null, $close = true)` | 2 | Stream a JSON row out (used by dump plugins). |

## Schema introspection — all driver-dependent

Each of these has ~5 definitions in adminer.php, one per driver; which is live depends
on the connection. Feature-test with `support()` before assuming.

| Function | n | Notes |
|---|--:|---|
| `tables_list()` | 1 | `name => type` for every table in the current database. |
| `fields($table)` | 2 | Column definitions for a table. |
| `create_sql($table, $autoIncrement, $style)` | 2 | The `CREATE TABLE` statement. Only 4 drivers implement it. |
| `support($feature)` | 5 | Whether the driver supports a feature (`'sql'`, `'view'`, …). **Use this instead of checking the driver name.** |
| `get_databases($flush)` | 1 | List of databases. |
| `is_view($tableStatus)` | 1 | |
| `table($name)` | 2 | The table name as adminer will render it. |
| `column_foreign_keys($table)` | 1 | |
| `get_driver($name)` | 1 | Driver metadata by name. |
| `driver()` | 1 | The live driver object. |
| `adminer()` | 7 | The `Adminer` instance — i.e. the plugin chain itself. Calling a hook on it dispatches through every plugin. |

## Emitting markup and scripts

| Function | n | Notes |
|---|--:|---|
| `script($code, $newline = "\n")` | 14 | Inline `<script>` **with the CSP nonce applied**. Never hand-write a `<script>` tag. |
| `script_src($url, $defer = false)` | 13 | External `<script src>`, nonce applied. |
| `nonce()` | 9 | The CSP nonce, when you must build the tag yourself. |
| `html_select($name, array $options, $value = "", $onchange = "", $labelledBy = "")` | 5 | |
| `optionlist($options, $selected = null, $useKeys = false)` | 3 | `<option>` list only. |
| `html_radios($name, array $options, $value = "", $separator = "")` | 2 | |
| `input_hidden($name, $value = "")` | 2 | Registered on our Latte engine as a function too. |
| `input_token()` | 1 | The CSRF token as a hidden input. Also registered on Latte. |
| `print_fieldset($id, $legend, $open = false)` | 2 | |
| `confirm($message = "", $selector = "qsl('input')")` | 2 | Attach a confirm handler. Note the `qsl()` caveat in the main skill. |
| `bold($string, $class = "")` | 2 | |
| `target_blank()` | 1 | `target="_blank" rel="noreferrer noopener"`. |
| `page_header($title, $error = "", $breadcrumb = [], $titlePrint = "")` | 1 | |
| `page_footer($missing = "")` | 1 | |

**CSP matters here.** The app sets a Content-Security-Policy, so an inline script
without the nonce is silently dropped by the browser. Use `script()`/`script_src()`,
and if a script does not run, check the console via `make debug` before assuming the
PHP is wrong.

## Session, request, settings

| Function | n | Notes |
|---|--:|---|
| `lang($message, $number = null)` | 17 | Translate. **Runs through sprintf** — a `%d` meant for JS becomes `0`. Use `{n}`. On a plugin, prefer `$this->lang()` so your own `$translations` apply. |
| `get_setting($key, $cookie = "adminer_settings", $connection = null)` | 6 | Read one of adminer's own persisted settings. |
| `save_settings(array $settings, $cookie = "adminer_settings")` | 1 | Write them. For *our* preferences use `Desktop\UserSettings` + `SettingKey` instead. |
| `restart_session()` | 3 | Reopen the session for writing — needed before a write, because adminer closes it early. |
| `stop_session($force = false)` | 1 | |
| `get_session($key)` | 1 | |
| `cookie($name, $value, $lifetime = 2592000)` | 1 | |
| `verify_token()` | 1 | CSRF check. Guard any state-changing POST with it — `AdminerDesktop::handlePost()` does. |
| `get_password()` | 3 | The current connection's password. |
| `redirect($location, $message = null)` | 3 | |
| `remove_from_uri($pattern = "")` | 1 | Current URI minus matching query params. |
| `where_link($index, $column, $value, $operator = "=")` | 1 | |
| `friendly_url($string)` | 1 | |
| `hidden_fields_get()` | 1 | Re-emit GET params as hidden inputs. |
| `get_url($url, $context)` | 1 | Fetch a URL — returns `[body, …]`. Used by `sql-gemini.php` to call an external API. |
| `rand_string()` | 1 | |
| `is_mail($value)` | 1 | |

## Constants

| Constant | n | Meaning |
|---|--:|---|
| `Adminer\SERVER` | 7 | Current server. Empty string means a Unix socket — which is why `AdminerDesktop::loginFormField()` rewrites the blank default to `127.0.0.1`. |
| `Adminer\DB` | 6 | Current database. |
| `Adminer\ME` | 5 | Base URL of the current script, for building links. |
| `Adminer\DRIVER` | 4 | Driver name. Prefer `support()` for capability checks. |
| `Adminer\LANG` | 3 | Current language code — the key into `$translations`. |
| `Adminer\JUSH` | 3 | Syntax-highlighter dialect. |
| `Adminer\VERSION` | 1 | Adminer's version. |

## Re-deriving any of this

The compiled adminer is minified and declarations wrap across lines, so line-based
grep fails. Flatten first:

```sh
tr '\n' ' ' < app/adminer.php > /tmp/flat.php
grep -oE "function +idf_escape *\([^)]*\)" /tmp/flat.php
```

For a full class or the whole function list, tokenise instead of grepping — run a
short script through the bundled PHP (`./bin/frankenphp php-cli script.php`) using
`token_get_all()`, matching `T_FUNCTION` and tracking brace depth.
