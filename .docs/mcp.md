# Letting an agent query your database

The app can expose your **saved connections that are signed in** to an AI agent over MCP — the
one the open window is on by default, any other when the agent names it — so you can ask about a
schema or have a query written without configuring a second connection or copying a password
anywhere.

It is off until you turn it on, it works only while the window is open and logged in, and it
cannot write unless you allow it.

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

- Point the agent at the app itself: `-mcp` is a small bridge built into the launcher, with no PHP to install and nothing to find.

Register it once. The port the app listens on changes every start, but the agent re-reads it per
message, so it keeps working across restarts without touching the config again.

- **Remove** on the same tab prints the command to unregister it.
- Use it when the app moves or is uninstalled: a stale registration only shows as a server that will not connect.

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
| `list_connections` | every saved connection: its id, name, system, environment, whether it is signed in |
| `current_connection` | which connection and database a call lands on, and what the queries really run in |
| `list_databases` | the databases a connection's login may open |
| `list_tables` | every table and view |
| `describe_table` | columns, types, nullability, defaults, primary key |
| `preview_table_data` | the first rows of a table |
| `execute_query` | run a read-only query |

Every tool but `list_connections` takes an optional `connection` (an id from `list_connections`)
and `database`. Without them a call goes to the connection and database the window is on, so the
agent no longer needs you to click over to the one it wants. Only a connection you are signed in
to can be queried; one you logged out of is listed, so the agent can ask you to sign in to it, and
nothing more. A call that named one does not move the window, nor where later calls go by default.
A signed-in login that no longer opens, because its server is down or its password changed, answers
with an error naming it, and Adminer signs it out, as it does with a login that fails in the window.

With no database open in the window, PostgreSQL connects to `postgres` and MS SQL to the login's
default; `current_connection` names that database and says none is open, rather than answering
an empty name. A database name that does not open is an error, never an answer from the one the
driver fell back to.

**Allowing writes** (the second switch on the tab) commits what `execute_query` changes — except
on a connection whose environment is set to *Prod* on the start screen, which stays read-only
whatever the switch says. The tool's description follows the switch, so the agent is never told a
write will be rolled back when it will not.

`execute_query` goes through Adminer's own guard for SQL it did not write. On PostgreSQL that is
**one statement, inside a read-only transaction**: a write is refused, and so is a second
statement smuggled in after a `COMMIT;`. On the other databases the statement runs inside a
transaction that is **always rolled back** (MySQL runs one statement per call anyway). Either way
it is the database refusing, not us reading the SQL and guessing. What comes back:

- At most 200 rows.
- A long value is cut and listed in `truncated_cells` with its full length.
- `whole_values: true` on `preview_table_data` or `execute_query` returns values whole.
- A value that is not text (BLOB, BINARY, control characters) comes back as hex, listed in `binary_cells`.

**None of that is the boundary — the login is.** A superuser still runs a program on the
database server inside a read-only transaction (`COPY … TO PROGRAM`), reads its files
(`pg_read_file`) and the password hashes (`pg_authid`), and MySQL commits a schema change
(`CREATE TABLE`, `ALTER TABLE`) immediately. Log the window in as a role that can only read:

```sql
-- PostgreSQL
CREATE ROLE agent LOGIN PASSWORD '…';
GRANT CONNECT ON DATABASE app TO agent;
GRANT USAGE ON SCHEMA public TO agent;
GRANT SELECT ON ALL TABLES IN SCHEMA public TO agent;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT ON TABLES TO agent;

-- MySQL / MariaDB: no FILE, no SUPER, nothing on mysql.*
CREATE USER 'agent'@'%' IDENTIFIED BY '…';
GRANT SELECT ON app.* TO 'agent'@'%';
```

Check it by having the agent try a `DELETE` and a `DROP`: both must fail with a permission
error. Leave out of the grants any table or column holding secrets of your own users (password
hashes, tokens, API keys) — a role that can read a table can read all of it.

**A login that can step out of the query is refused**, before any tool but `list_connections`
runs, with the privilege to take away named in the answer:

- PostgreSQL: a superuser; a member of `pg_read_server_files`, `pg_write_server_files` or
  `pg_execute_server_program`; `EXECUTE` on `pg_read_file`, `pg_read_binary_file`, `pg_ls_dir`,
  `lo_import` or `lo_export`; and before 16, `CREATEROLE`, which could grant itself those roles.
- MySQL and MariaDB: any privilege on `*.*` but `USAGE` — a global `SELECT` reads `mysql.user`
  and its password hashes — anything on `mysql.*` or a wildcard that matches it, and `PROXY`.

A login whose privileges cannot be read is refused too. There is no switch to allow it anyway,
and allowing writes does not change it: it is about the server, not the data. `ALL` on one
database is that database's data, and is left to the writes switch. The other databases are not
checked, and on MySQL 8 a privilege held only through a granted role is not seen.

## Answers you might get instead

- The tools stay listed while the app is closed, so the agent asks you to open it instead of building its own way in.
- Each call then answers with one of these; once the app is open, the next call goes through.

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
2026-08-03T14:22:19Z	tools/call	list_tables	[pgsql|db.example.com|app shop]
2026-08-03T14:40:02Z	bridge-exit		the client sent interrupt
```

A call on another connection or database carries it in brackets. `bridge-exit` is the process the
agent started stopping, and why: *the client closed stdin* or *the client sent …* is the agent
letting go, which it does not log itself — a server that is just gone otherwise looks like one
that crashed.

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
settings (mode 600) holding the address of the app's MCP gate, the socket it is reached on, a
token for it, and which connection the window is on. That file is how the agent finds the window.

**Nothing in it unlocks a password, and the session never leaves the app.** Adminer keeps your
database passwords in its session, encrypted with a key in a cookie — so that cookie and the
session id are exactly what an agent must not have, and the agent runs as you: file permissions
keep out other accounts, not it. The launcher holds the session in memory and adds it to each
call that comes through the gate; the token opens the MCP endpoint and no other page. Close the
window or log out and the access goes with it.

The gate is the launcher's, so served without it (`make serve`) there is no MCP at all.

- **Calls go over a socket only your account can open**, in a private folder the app makes at start, not over the port the file names. A port outlives a quit app and anyone can bind it next; a stale `mcp.json` then reaches nothing.
- **Only a loopback address is followed.** An `mcp.json` naming anywhere but `127.0.0.1` reads as no handshake.
- **An agent's statement stops after 45 s**, in the database itself (PostgreSQL `statement_timeout`, MariaDB `max_statement_time`, MySQL `max_execution_time`, which covers SELECT only), so nothing keeps running once the agent has been told the call gave up. A stricter limit already set for the login is kept. Other drivers have no such limit, and the agent's own SQL can lift it: it is a safety net, not a permission — that is still the login's.
