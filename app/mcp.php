<?php
declare(strict_types=1);

/** PHP's MCP stdio bridge: stdin and stdout here, the running app over HTTP there.
*
* Nothing that ships spawns this. An installed app registers its launcher, whose -mcp flag runs
* the Go bridge (launcher/mcpbridge) with no PHP child at all:
*
*     claude mcp add adminer-desktop -- adminer-desktop -mcp
*
* The per-platform commands are in .docs/mcp.md, so there is one copy to keep true.
*
* This one stays as the baseline the e2e suite holds the Go bridge to (tests/e2e/bootstrap/
* Bridge.php), and for working on the app with no launcher built. A bare entry like api.php —
* served by nothing, run by `frankenphp php-cli` — so it turns the autoloader on itself and hands
* straight over to Desktop\Mcp\Stdio. Both paths absolute, so the agent's working directory does
* not matter; bin/frankenphp is the copy `make fetch` downloads:
*
*     claude mcp add adminer-desktop -- "$PWD/bin/frankenphp" php-cli "$PWD/app/mcp.php"
*
* A built launcher's -mcp works from any directory too, since it needs neither bin/ nor app/.
*
* Register it once and it keeps working across restarts, because the thing that changes — the
* port the launcher binds — is read fresh on every message rather than baked into the config.
* Nothing is answered until the feature is switched on in the app's settings.
*/

require_once __DIR__ . "/vendor/autoload.php";

(new Desktop\Mcp\Stdio())->run(STDIN, STDOUT);
