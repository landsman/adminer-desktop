<?php
declare(strict_types=1);

/** Is a shortcut written the way its platform writes it? Apple's modifier order run together on a
 * mac, Microsoft's joined by + elsewhere — whatever order the list gives the keys in. The e2e only
 * sees the platform it runs on, so this is what covers the other two.
 */

use Desktop\Os;
use Desktop\Shortcuts\Key;
use Desktop\Shortcuts\Shortcuts;
use Tester\Assert;

require dirname(__DIR__) . "/bootstrap.php";

$keys = [Key::Mod, Key::Shift, Key::Enter];
Assert::same('⇧⌘↩', Shortcuts::write($keys, Os::Mac));
Assert::same('Ctrl+Shift+Enter', Shortcuts::write($keys, Os::Windows));
Assert::same('Ctrl+Shift+Enter', Shortcuts::write($keys, Os::Linux));
Assert::same('⌘R', Shortcuts::write([Key::Mod, 'R'], Os::Mac));
Assert::same('Ctrl+Alt+↑', Shortcuts::write([Key::Alt, Key::Mod, Key::Up], Os::Windows));
Assert::same('⌥⇧⌘↑', Shortcuts::write([Key::Mod, Key::Shift, Key::Alt, Key::Up], Os::Mac));

echo "shortcuts ok\n";
