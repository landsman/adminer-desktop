<?php
declare(strict_types=1);

/** Does a theme's palette reach the page, and only its colours? A theme comes from any git repository
 * and the result lands in a <style>, so something other than a colour getting through is the failure.
 */

use Desktop\Settings\Theme\OmarchyTheme;
use Tester\Assert;

require dirname(__DIR__) . "/bootstrap.php";

$dark = <<<'TOML'
mode = "dark"

accent = "#7aa2f7"
background = "#1a1b26"
dark_background = "#13141c"
foreground = "#a9b1d6"
magenta = "#ad8ee6"
TOML;

$css = OmarchyTheme::css($dark, 'auto');
Assert::notContains('color-scheme', $css); // the meta decides, or an override stops working
Assert::contains('--ad-accent:#7aa2f7;', $css);
Assert::contains('--bg:#1a1b26;', $css);
Assert::contains('--ad-panel:#1a1b26;', $css);
Assert::contains('--ad-accent-fg:#1a1b26;', $css);
Assert::contains('--ad-menu-bg:#13141c;', $css);
Assert::contains('--fg:#a9b1d6;', $css);
Assert::notContains('ad8ee6', $css); // a colour nothing uses

// Pinning the theme's own side changes nothing, and the other side leaves the page out of the theme.
Assert::same($css, OmarchyTheme::css($dark, 'dark'));
Assert::same('', OmarchyTheme::css($dark, 'light'));
Assert::contains('--bg:#ffffff;', OmarchyTheme::css("mode = \"light\"\nbackground = \"#ffffff\"\n", 'light'));
Assert::same('', OmarchyTheme::css("mode = \"light\"\nbackground = \"#ffffff\"\n", 'dark'));

Assert::same('', OmarchyTheme::css('', 'auto'));

// Read from where omarchy-theme-set leaves the current theme, and nothing when there is none.
$home = sys_get_temp_dir() . '/omarchy-theme-test-' . getmypid();
putenv("HOME=$home");
Assert::same('', OmarchyTheme::current('auto'));
Assert::null(OmarchyTheme::currentMode());
mkdir("$home/.local/state/omarchy/current/theme", 0700, true);
file_put_contents("$home/.local/state/omarchy/current/theme/colors.toml", $dark);
Assert::same($css, OmarchyTheme::current('auto'));
Assert::same('dark', OmarchyTheme::currentMode());
// A page must not change side for a file that says nothing.
file_put_contents("$home/.local/state/omarchy/current/theme/colors.toml", "mode = \"light\"\n");
Assert::null(OmarchyTheme::currentMode());
unlink("$home/.local/state/omarchy/current/theme/colors.toml");
for ($dir = "$home/.local/state/omarchy/current/theme"; $dir !== dirname($home); $dir = dirname($dir)) {
	rmdir($dir);
}

// What is not a hex colour stays out, escapes above all.
foreach (
	[
		'accent = "#fff;}</style><script>alert(1)</script>"',
		'accent = "red"',
		'accent = "#12345"',
		'accent = "#1234567"',
		"accent = \"#123456\"; background: url(x)",
		"accent = \"#123456\nbackground = url(x)\"",
	] as $hostile
) {
	$out = OmarchyTheme::css($hostile, 'auto');
	Assert::notContains('script', $out);
	Assert::notContains('url(', $out);
	Assert::notContains('--ad-accent', $out);
}
