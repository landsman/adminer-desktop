<?php
declare(strict_types=1);

/** The MCP server an agent spawns: stdin and stdout here, the running app over HTTP there.
*
* A bare entry like api.php — served by nothing, run by `frankenphp php-cli`, so it turns the
* autoloader on itself and hands straight over to Desktop\Mcp\Stdio. The work lives there so it
* can be tested without a process to spawn; what stays here is how to register it.
*
* Register it once and it keeps working across restarts, because the thing that changes — the
* port the launcher binds — is read fresh on every message rather than baked into the config.
* Two arguments, because this is a PHP script with no shebang: the bundled interpreter, then us.
*
* Installed on macOS (mind the space in the bundle name):
*
*     claude mcp add adminer -- "/Applications/Adminer Desktop.app/Contents/MacOS/frankenphp" \
*         php-cli "/Applications/Adminer Desktop.app/Contents/Resources/app/mcp.php"
*
* Installed from the .deb:
*
*     claude mcp add adminer -- /usr/lib/adminer-desktop/frankenphp \
*         php-cli /usr/lib/adminer-desktop/app/mcp.php
*
* From a checkout, with absolute paths — the agent does not run this from the repo root:
*
*     claude mcp add adminer -- "$PWD/bin/frankenphp" php-cli "$PWD/app/mcp.php"
*
* Nothing is answered until the feature is switched on in the app's settings, and only ever
* against the database the window is currently logged into.
*/

require_once __DIR__ . "/vendor/autoload.php";

(new Desktop\Mcp\Stdio())->run(STDIN, STDOUT);
