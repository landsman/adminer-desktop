<?php
declare(strict_types=1);

namespace Desktop;

/** The environment variables the launcher sets and the app reads — the whole contract in one
* list, so a read cannot quietly mistype a name only the Go side would know is wrong. The
* launcher (main.go and friends) sets these by the same literal names; this is the PHP end.
*
* Read one with ->get(): `$dir = Env::DataDir->get()`. The case names say what each holds.
*/
enum Env: string {
	/** Durable data directory (Go's os.UserConfigDir); holds adminer.key and settings.json. */
	case DataDir = 'ADMINER_DESKTOP_DATA';
	/** Set under -debug: turns on Tracy and the web inspector's own affordances. */
	case Debug = 'ADMINER_DESKTOP_DEBUG';
	/** `make demo` only: the throwaway connection demo-login.js fills and submits. */
	case Demo = 'ADMINER_DESKTOP_DEMO';
	/** The launcher's own path (Go's os.Executable), so the MCP panel can print the exact
	* registration command for this install. Unset when app/ is served without the launcher. */
	case Exe = 'ADMINER_DESKTOP_EXE';
	/** Where the launcher keeps its logs — ~/Library/Logs on macOS, the data dir elsewhere
	* (launcher/prefs.go logDir). The MCP request log goes here so the Open Logs menu item
	* already opens it; unset when app/ is served without the launcher. */
	case Logs = 'ADMINER_DESKTOP_LOGS';

	/** host:port of the launcher's MCP gate (launcher/mcpgate), where the handshake sends an agent.
	* Unset without the launcher, and then there is no MCP: nothing holds the session to lend. */
	case McpGate = 'ADMINER_DESKTOP_MCP_GATE';
	/** The token the gate asks the bridge for. Opens the MCP endpoint and nothing else, so it may
	* sit in mcp.json; it keeps out a web page posting to loopback, which cannot read that file. */
	case McpToken = 'ADMINER_DESKTOP_MCP_TOKEN';
	/** The unix socket the gate hears the window's session on (Mcp\Endpoint::report()). */
	case McpReport = 'ADMINER_DESKTOP_MCP_REPORT';

	/** The launcher's build version, `git describe --tags --always --dirty` from the Makefile:
	* a tag, a tag with commits past it (beta-v0.7.2-15-gaa1715f), or "dev". Unset when app/ is
	* served without the launcher. See Desktop\AppVersion. */
	case Version = 'ADMINER_DESKTOP_VERSION';

	/** The value of this environment variable, or false when it is not set (getenv's contract). */
	function get(): string|false {
		return getenv($this->value);
	}
}
