# Letting an agent query your database

The app can expose the database **the open window is logged into** to an AI agent over MCP, so
you can ask about a schema or have a query written without configuring a second connection or
copying a password anywhere.

It is off until you turn it on, it works only while the window is open and logged in, and it
cannot write.

## Turn it on

There is no switch in the settings dialog yet, so for now it is one line in the preferences
file — the same file the dialog writes:

| | |
| --- | --- |
| macOS | `~/Library/Application Support/Adminer Desktop/settings.json` |
| Linux | `~/.config/Adminer Desktop/settings.json` |

```json
{ "mcp": true }
```

If the file already exists, add the `"mcp": true` key rather than replacing what is there.
Restart is not needed — the next page you load in the app picks it up.

## Register it with your agent

Point the agent at the app itself. There is no PHP to install and no path to hunt for: the
launcher ships its own and finds it.

```sh
# Linux, installed from the .deb (the only one already on PATH)
claude mcp add adminer -- adminer-desktop -mcp

# macOS
claude mcp add adminer -- "/Applications/Adminer Desktop.app/Contents/MacOS/adminer-desktop" -mcp

# Linux, unpacked from the tarball — wherever you put it
claude mcp add adminer -- ~/adminer-desktop/adminer-desktop -mcp
```

Register it once. The port the app listens on changes every start, but the agent re-reads it
per message, so it keeps working across restarts without touching the config again.

Windows is not covered yet — see the platform table in [install.md](install.md).

For other MCP clients, the command is the same; only the way you register it differs.

## What the agent can do

| Tool | |
| --- | --- |
| `current_connection` | which driver, server and database the window is on |
| `list_tables` | every table and view |
| `describe_table` | columns, types, nullability, defaults, primary key |
| `preview_table_data` | the first rows of a table |
| `execute_query` | run a read-only query |

`execute_query` runs inside a transaction that is **always rolled back**, so a statement that
turns out to write leaves nothing behind — that is the database refusing, not us reading the SQL
and guessing. Results are capped at 200 rows and long values are truncated, so one wide column
cannot crowd out the answer.

One caveat worth knowing: rollback undoes writes, but on MySQL a schema change (`CREATE TABLE`,
`ALTER TABLE`) commits itself and cannot be undone. If you want writes to be *impossible* rather
than undone, log the window in as a read-only database user.

## Answers you might get instead

| Message | What it means |
| --- | --- |
| *"not running, or database access for agents is switched off"* | the app is closed, or `"mcp": true` is not set |
| *"stopped answering — the window was probably closed"* | it was running when you registered; it is not now |
| *"not connected to a database"* / *"session has expired"* | the app is open but logged out — log in again |

## Turning it off

Set `"mcp": false`, or delete the key. The next page you load in the app retracts the handshake
file, so nothing can reach the database through it afterwards — turning it off removes the
pointer rather than just declining to honour it.

## What is stored, and where

While the feature is on and you are logged in, the app keeps a small `mcp.json` beside your
settings holding the address of the running window and its session cookies, readable only by
your account (mode 600). That file is how the agent finds the window and borrows its login.

**No database password is ever written to it.** The agent gets the session you already have, for
as long as you have it: close the window or log out and the access goes with it.
