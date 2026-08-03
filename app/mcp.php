<?php
declare(strict_types=1);

/** The MCP server an agent spawns: stdin and stdout here, the running app over HTTP there.
*
* A bare entry like api.php — served by nothing, run by `frankenphp php-cli`, so it turns the
* autoloader on itself and hands straight over to Desktop\Mcp\Stdio. The work lives there so it
* can be tested without a process to spawn; what stays here is how to register it.
*
* Nothing spawns this directly. The launcher's -mcp flag does, because it already knows where
* frankenphp and app/ are and the agent should not have to:
*
*     claude mcp add adminer -- adminer-desktop -mcp                      # .deb, on PATH
*     claude mcp add adminer -- "/Applications/Adminer Desktop.app/Contents/MacOS/adminer-desktop" -mcp
*
* Register it once and it keeps working across restarts, because the thing that changes — the
* port the launcher binds — is read fresh on every message rather than baked into the config.
*
* From a checkout there is no installed binary to point at, so run this the long way, from the
* repo root (resolve() looks for bin/ and app/ relative to it):
*
*     claude mcp add adminer -- "$PWD/bin/frankenphp" php-cli "$PWD/app/mcp.php"
*
* Nothing is answered until the feature is switched on in the app's settings, and only ever
* against the database the window is currently logged into.
*/

require_once __DIR__ . "/vendor/autoload.php";

(new Desktop\Mcp\Stdio())->run(STDIN, STDOUT);
