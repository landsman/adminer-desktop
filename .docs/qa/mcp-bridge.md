# Testing the MCP bridge

The path an agent's call takes is: the agent spawns `adminer-desktop -mcp`, a Go bridge
(`launcher/mcpbridge`). The bridge reads `mcp.json` and posts to the launcher's gate
(`launcher/mcpgate`) over a private unix socket. The gate adds the window's session and calls
frankenphp, and `Desktop\Mcp\Tools` runs the SQL. This page lists what checks each link in that
path, how to try it by hand, and what is known not to be covered.

`app/mcp.php` (`Desktop\Mcp\Stdio`) is PHP's copy of the bridge. The installed app does not use
it; it stays as the baseline the e2e suite holds the Go bridge to.

## What runs on its own

- **`make e2e`** runs every MCP scenario twice: once through the PHP bridge, once through the Go
  bridge (`mise run e2e-bridge-go`). A difference between the two fails the run.
  - `features/shell/mcp-bridge.feature`: the bridge against an HTTP stub. It covers the app not
    running, a closed window, an expired session, an empty answer, an error status, a BOM, a
    handshake that points off this machine, one with no socket, a version negotiated offline, `server/discover`, a
    quiet socket stdin, and the stream framing.
  - `features/shell/mcp.feature`: the real gate and app on postgres. It covers the handshake and
    its permissions, the gate refusing anything without the token, reads, writes on and off,
    a Prod connection, other connections, the 45 s statement limit, and a stricter limit the
    login already has.
  - `features/mysql/mcp.feature` and `features/mysql/mcp-limits.feature`: binary values, and the
    statement limit on MySQL.
- **`go test -race ./launcher/mcpbridge ./launcher/mcpgate`**:
  - **Answers:** the status and body rules, the BOM, redirects that are not followed, the idle
    timeout against a slow body.
  - **Concurrency:** a ping that does not queue behind calls, and a cancel that hangs up on the
    window.
  - **Socket:** posting over it, and refusing one in an open directory.
  - **Data dir:** it is always the launcher's own, whatever the environment says.
  - **Gate:** it hangs up on frankenphp when the client hangs up on the socket.
- **Tests that are known to fail when their fix is taken out:** the quiet socket (the fix from
  [#183](https://github.com/landsman/adminer-desktop/pull/183)), the statement limit, the gate's hang-up, and the bridge's cancel.

## Trying it by hand

Use an isolated home, so your installed app and its saved connections stay out of it:

```sh
make build
docker start adminer-demo-pg 2>/dev/null || docker run -d --name adminer-demo-pg \
  -e POSTGRES_PASSWORD=demo -e POSTGRES_DB=demo -p 55432:5432 postgres:18-alpine
docker exec -i adminer-demo-pg psql -U postgres -d demo < tests/e2e/seed/pgsql.sql   # once it is up
H=$(mktemp -d); D="$H/Library/Application Support/Adminer Desktop"   # Linux: $H/.config/Adminer Desktop
mkdir -p "$D" && chmod 700 "$D" && echo '{"mcp":true}' > "$D/settings.json"
HOME=$H ADMINER_DESKTOP_DEMO='pgsql 127.0.0.1:55432 agent demo demo' ./build.noindex/adminer-desktop -dev
```

- **Use the `agent` role, not `postgres`.** The app refuses a superuser login on purpose.
- **A real client, with nothing registered:** write an MCP config with
  `{"command": "<abs path>/build.noindex/adminer-desktop", "args": ["-mcp"], "env": {"HOME": "<H>"}}`,
  and run `claude -p --strict-mcp-config --mcp-config <file>` with the prompt on stdin.
- **Several agents at once:**
  `go build -o build.noindex/mcpload ./tests/e2e/harness/mcpload && HOME=$H ./build.noindex/mcpload -bridge $PWD/build.noindex/adminer-desktop -agents 5 -calls 10`.
  It exits non-zero on any mismatch.
- **The full step list** is the test plan on
  [#186](https://github.com/landsman/adminer-desktop/pull/186#issuecomment-6385827624).

## Results on macOS (October 2026, the build of [#186](https://github.com/landsman/adminer-desktop/pull/186))

- **Passed:**
  - a real client listing and reading through the built launcher;
  - the handshake file at 0600 and the socket in a 0700 directory;
  - a ping answered during a 5 s query;
  - writes refused when off and committed when on;
  - quit and restart with the same bridge process, then 95 s of quiet;
  - AI access turned off;
  - a listener on the old port receiving nothing;
  - a handshake pointing at another host, which is not followed;
  - the `bridge-exit` line in the log;
  - a call naming another signed-in connection runs as that login, a superuser one is refused,
    and the window stays where it was;
  - signing out in the window: the agent is told to log in again (it was a JSON-RPC error that
    failed `initialize`, fixed in [#186](https://github.com/landsman/adminer-desktop/pull/186)).
- **Statement limit:** `pg_sleep(70)` stopped at 45 s, and nothing was left in
  `pg_stat_activity`. The same on a call forwarded to another connection, at 46 s.
- **Load:** 5 agents × 10 calls of 2 s came back 50/50, every one with its own id. Pings came
  back in 0.1 s, against 4.1 s before the queue fix. A window page took 5–11 ms during the run,
  against 5 ms at rest, on a machine with 36 PHP threads.
- **Cmd+Q:** no bridge is left, but frankenphp keeps running under init. The gate's directory
  stays too: 292 of them had piled up, and the gate now sweeps them at start. The server is
  [#188](https://github.com/landsman/adminer-desktop/issues/188).
- **Not done yet:** closing the lid; anything on Linux by hand.

## Known weak spots

- **Windows has no MCP at all.** The launcher starts no gate there (`launcher/main.go`), so the
  bridge only ever says the app is not running. The Windows code paths (`private_windows.go`,
  the handshake rename retry) have been through `go vet` and nothing else.
- **On a small machine** frankenphp has 2 PHP threads per core: 8 on four cores. Each agent runs
  up to 4 calls at once, and a call to another connection holds 2 threads. With a few busy agents
  the window will wait. This has not been measured.
- **The statement limit is a safety net, not a boundary.**
  - The agent's own SQL can lift it, for example with `SET statement_timeout = 0` first.
  - With writes on, a string of several statements is timed one statement at a time.
  - MySQL's limit covers SELECT only, so a slow UPDATE on MySQL runs on.
  - Other drivers have no limit at all.
  - What the agent may do is decided by the login's privileges.
- **MariaDB's `max_statement_time` has never run.** The fixture has MySQL 8 and postgres.
- **A postgres timeout is recognised by its English text.** A server set to another language
  answers with its own error instead of the app's explanation.
- **A cancel stops the HTTP call, not a database query already running.** PHP cannot interrupt a
  query in progress, so the statement limit is what ends it.
- **The session's own limit is put back after the call, but untested behind a pooler.** That is
  the only place where it would matter.
- **The Linux socket check was tested by changing a directory's mode,** not with a second
  account actually creating one in `/tmp`.
- **The PHP bridge's 60 s timeout has no e2e scenario,** because one would take a minute.
  The Go bridge's timeout is tested with a short limit.
- **A fresh clone needs `make`** before a bare `go build` or gopls works. The bridge embeds
  `launcher/mcpbridge/data.json`, which `make mcp-bridge-data` generates.
- **Upgrading from the PHP bridge:** remove the registration and add it again. The old
  `mcp.json` has no socket, so it is not followed until the window has loaded a page.
- **Found while testing, and not fixed by [#186](https://github.com/landsman/adminer-desktop/pull/186):** with writes on, a committed statement that
  returns no rows, such as a plain `INSERT`, is reported to the agent as an error. An agent
  that retries it writes twice.
