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
| **Agent writes through a read tool**, including a `COMMIT` smuggled into the query | Changed data with writes switched off | Postgres: Adminer's untrusted-SQL guard, a read-only transaction. MySQL: the statement runs and is rolled back | `features/shell/mcp.feature` |
| **Agent writes to a connection marked Prod** with writes allowed | Changed production data | `Desktop\Mcp\Endpoint` keeps Prod read-only whatever the setting says | `features/shell/mcp.feature` |
| **Agent reads the session or a password from the handshake** | Every database the user is signed in to | The gate keeps the session in memory, the handshake carries only its URL and token | `features/shell/mcp.feature` |
| **Handshake or a redirect sends the bridge elsewhere** | The token and the agent's SQL | The bridge accepts only a loopback URL and follows no redirect | `launcher/mcpbridge/bridge_test.go` |
| **Another account reads the key or the settings** | Stored database passwords | Data dir `0700`; `adminer.key` and the handshake `0600` | Not tested |
| **Tampered Adminer or FrankenPHP download** | Code running with access to every database | `checksums.txt` and FrankenPHP's build provenance | `make verify` |

## Accepted

- **Cross-site POST to `api.php`:** a page that finds the random port can change UI preferences. No
  token and no Origin check, because the values are validated and clamped. Revisit once an action
  does more than that (`app/api.php`).

## When writing an e2e feature

- Add the hostile case beside the happy one: the wrong Host, a missing field, a request with no
  session, a statement that tries to get out of its guard.
- Assert what was left behind, not only the answer. A refusal that still wrote is a failure.
- `curl` can forge a header but it is not an attack from a browser. Say so where it matters.
