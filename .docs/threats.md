# Attacks we design against

What someone hostile could try, what it would get them, and what stops it. Read this before adding
an endpoint, anything an agent can reach, or an e2e feature. A new attack gets a row here, in the
same PR as its defence or its acceptance.

Who we assume is hostile:

- **A web page** open in any browser on the machine.
- **An agent** using the MCP tools: it may be following instructions hidden in the data it reads.
- **Another account** on the same computer.
- **A tampered download** of something the build pulls in.

## Defended

| Attack | What it would get | What stops it | Where it is checked |
|---|---|---|---|
| **DNS rebinding:** a page points its own domain at the server's port and reads the answers as same-origin | The login form as a proxy to guess database passwords, and the preference writes. No session: cookies are bound to the name | `launcher/Caddyfile` answers 403 to any Host but `127.0.0.1` and `localhost` | `features/shell/host.feature` |
| **Forged Host at the MCP gate** | The window's session, lent to the caller | `launcher/mcpgate` checks Host and method, and requires the token | `launcher/mcpgate/mcpgate_test.go` |
| **Local process forges a session report**, on Windows a loopback port any process or page can post to | Agent calls run in the forger's session, or against a path other than the MCP endpoint | A report needs the key only frankenphp's environment holds; it reads nothing back | `launcher/mcpgate/mcpgate_test.go` |
| **Another account takes the report port** after the launcher dies and frankenphp lives on | The key, and the session cookies that decrypt the stored passwords | PHP sends nothing until the listener proves it holds the key | `launcher/mcpgate/mcpgate_test.go` |
| **Local process holds report connections open** to exhaust sockets and stop the gate hearing a sign-out | The last session lent on after sign-out | A deadline per connection; `Accept` errors are waited out | `launcher/mcpgate/mcpgate_test.go` |
| **Another Windows account connects to the bridge's socket**: Windows lets anyone traverse a directory, so the directory's ACL alone stops nothing | The token and the agent's SQL | The gate's directory is created private and its files inherit that; the bridge checks both | `launcher/mcpbridge`, `windows-ci` workflow |
| **Another Windows account reads the token from `mcp.json`**, whose `chmod` does nothing there, in a data dir outside the profile | The window's session, if the token alone opened the gate | The token only works over that private socket: nothing listens on the URL's port | `launcher/mcpbridge`, `features/shell/mcp.feature` |
| **Agent writes on postgres through a read tool**, including a `COMMIT` smuggled into the query | Changed data with writes switched off | Adminer's untrusted-SQL guard and a read-only transaction | `features/shell/mcp.feature` |
| **Agent changes rows on MySQL through a read tool** | Changed data with writes switched off | The statement runs in a transaction that is rolled back. A schema change escapes it, see Accepted | `features/mysql/mcp-writes.feature`, an `INSERT` only |
| **Agent writes to a connection marked Prod** with writes allowed | Changed production data | `Desktop\Mcp\Endpoint` keeps Prod read-only whatever the setting says. On MySQL only as far as the row above | `features/shell/mcp.feature` |
| **Agent's login reaches beyond its data:** a superuser's `COPY … TO PROGRAM`, a global `SELECT` reading `mysql.user` | The database server, its files and password hashes | `Tools::refuseOverprivileged()` refuses the login before any tool but `list_connections` | `features/shell/mcp.feature`, `features/shell/mcp-bridge.feature` |
| **A page posts wrong logins to `127.0.0.1`**, which passes the Host check, to lock the user out | 30 failures stop every login for 30 minutes | Adminer's `verify_token()` wants the page's token and `Sec-Fetch-Site: same-origin`, so the post never reaches the counter, and the page cannot read the token | Not in the suite. Tried by hand with a form and a `fetch` from `localhost`, in Chromium and WebKit: the counter stayed |
| **Agent reads the session or a password from the handshake** | Every database the user is signed in to | The gate keeps the session in memory, the handshake carries only its URL and token | `features/shell/mcp.feature` |
| **Handshake or a redirect sends the bridge elsewhere** | The token and the agent's SQL | The bridge accepts only a loopback URL and follows no redirect | `launcher/mcpbridge/bridge_test.go` |
| **Another account reads the key or the settings** | Stored database passwords | Data dir `0700`; `adminer.key` and the handshake `0600` | Not tested |
| **Tampered Adminer or FrankenPHP download** | Code running with access to every database | `checksums.txt` and FrankenPHP's build provenance | `make verify` |

## Accepted

- **Cross-site POST to `api.php`:** a page that finds the random port can change UI preferences. No
  token and no Origin check, because the values are validated and clamped. Revisit once an action
  does more than that (`app/api.php`).
- **A schema change on MySQL with writes off:** `DROP TABLE` commits at once, so the rollback does
  not undo it. `refuseOverprivileged()` does not stop it either: it judges reach beyond the data,
  and `ALL` on one database passes on purpose. Only a login without that privilege stops it, which
  `.docs/mcp.md` tells the user to set up and nothing enforces.

## When writing an e2e feature

- Add the hostile case beside the happy one: the wrong Host, a missing field, a request with no
  session, a statement that tries to get out of its guard.
- Assert what was left behind, not only the answer. A refusal that still wrote is a failure.
- `curl` can forge a header but it is not an attack from a browser. Say so where it matters.
