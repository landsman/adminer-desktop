# Letting an agent query your database

The app can expose the database **the open window is logged into** to an AI agent over MCP, so
you can ask about a schema or have a query written without configuring a second connection or
copying a password anywhere.

It is off until you turn it on, it works only while the window is open and logged in, and it
cannot write.

## Turn it on

**Settings → AI access → “Let an AI agent query this database”**, then Save. The tab also shows
whether it is actually reachable: on is not the same as working, because nothing can be queried
until the window is logged in to a database.

## Register it with your agent

The same tab prints the command for **this** install, ready to copy — one per agent, because the
command is identical and only the CLI in front of it differs. The app knows where it was put,
which differs by platform and by how it was installed, so there is nothing to look up:

```sh
claude mcp add adminer-desktop -- "<the path the settings tab shows>" -mcp
codex   mcp add adminer-desktop -- "<the path the settings tab shows>" -mcp
gemini  mcp add adminer-desktop -- "<the path the settings tab shows>" -mcp
```

Point the agent at the app itself. There is no PHP to install and no interpreter to find: the
launcher ships its own and resolves it.

Register it once. The port the app listens on changes every start, but the agent re-reads it per
message, so it keeps working across restarts without touching the config again.

Windows is not covered yet — see the platform table in [install.md](install.md).

For other MCP clients, the command is the same; only the way you register it differs — most want
it written into a config file as a stdio server rather than added from a CLI.

What the agent is told when something is wrong — the app not running, the window closed, the
session expired — is translated into the app's language, so the answer that reaches you is in the
language the rest of the app is in. The tool names and their descriptions stay English: those are
instructions to the model, and a tool that is called something different per locale is one no
shared prompt can refer to.

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

One caveat worth knowing: rollback undoes data changes, but some databases commit a schema
change (`CREATE TABLE`, `ALTER TABLE`) immediately, so on those it cannot be undone. If you want
writes to be *impossible* rather than undone, log the window in as a read-only database user.

## Answers you might get instead

| Message | What it means |
| --- | --- |
| *"not running, or database access for agents is switched off"* | the app is closed, or the AI access tab is not switched on |
| *"stopped answering — the window was probably closed"* | it was running when you registered; it is not now |
| *"not connected to a database"* / *"session has expired"* | the app is open but logged out — log in again |

## Seeing what the agent did

Every request is logged — one line per call, with the time, the method, the tool and what was
asked (the SQL for a query, the table for anything else). The **Open Logs** menu item opens the
folder it sits in; the file is `mcp-YYYY-MM-DD.log`.

```
2026-08-03T14:22:07Z	tools/call	list_tables
2026-08-03T14:22:11Z	tools/call	execute_query	SELECT id, email FROM users LIMIT 20
```

A file per day, so today's cannot grow without bound and yesterday's is left exactly as it was.
Files older than two weeks are removed on the next write, so a machine left running does not
accumulate them.

**What was asked is logged; what came back is not.** Results are the contents of your database,
and a plaintext copy of those sitting beside it would be a worse leak than the access being
recorded. The file is readable only by your account.

## Turning it off

Untick it in **Settings → AI access** and Save. The next page you load retracts the handshake
file, so nothing can reach the database through it afterwards — turning it off removes the
pointer rather than just declining to honour it.

## What is stored, and where

While the feature is on and you are logged in, the app keeps a small `mcp.json` beside your
settings holding the address of the running window and its session cookies, readable only by
your account (mode 600). That file is how the agent finds the window and borrows its login.

**No database password is ever written to it.** The agent gets the session you already have, for
as long as you have it: close the window or log out and the access goes with it.
