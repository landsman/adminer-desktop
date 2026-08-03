<?php
declare(strict_types=1);

/** The MCP server an agent spawns: stdin and stdout here, the running app over HTTP there.
*
* A bare entry like api.php — served by nothing, run by `frankenphp php-cli`, so it turns the
* autoloader on itself and hands straight over to Desktop\Mcp\Stdio. The work lives there so it
* can be tested without a process to spawn; what stays here is how to register it.
*
* Nothing spawns this directly, and nobody who installed the app should ever name a path into
* it. The bundle ships its own frankenphp beside the launcher (Makefile, bundle:), so there is
* no PHP to install and nothing on PATH to find — the launcher's -mcp flag resolves both and
* the agent is pointed at the app itself:
*
*     claude mcp add adminer -- adminer-desktop -mcp                      # .deb, on PATH
*     claude mcp add adminer -- "/Applications/Adminer Desktop.app/Contents/MacOS/adminer-desktop" -mcp
*
* Register it once and it keeps working across restarts, because the thing that changes — the
* port the launcher binds — is read fresh on every message rather than baked into the config.
*
* Working on the app rather than using it, there is no bundle to point at, so name the two
* directly. bin/frankenphp there is the copy `make fetch` downloads into the checkout — it is
* gitignored, and a fresh clone has none until then. Both paths absolute, so the agent's
* working directory does not matter:
*
*     claude mcp add adminer -- "$PWD/bin/frankenphp" php-cli "$PWD/app/mcp.php"
*
* The built launcher would work too, but only from the repo root: resolve()'s dev case looks
* for bin/ and app/ relative to the working directory, and an agent does not promise one.
*
* Nothing is answered until the feature is switched on in the app's settings, and only ever
* against the database the window is currently logged into.
*/

require_once __DIR__ . "/vendor/autoload.php";

(new Desktop\Mcp\Stdio())->run(STDIN, STDOUT);
