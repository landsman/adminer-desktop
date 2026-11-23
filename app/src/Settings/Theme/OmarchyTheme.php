<?php
declare(strict_types=1);

namespace Desktop\Settings\Theme;

/** The page in the colours of the Omarchy theme that is on now. Each theme has its own, so they are
* read from the theme at each page load rather than shipped: switching themes reaches the next page.
*/
class OmarchyTheme {
	/** colors.toml's key => the tokens it sets. What html derives from them follows, being on :root. */
	private const array TOKENS = [
		'background' => ['--bg', '--ad-panel', '--ad-accent-fg'],
		'dark_background' => ['--ad-menu-bg'],
		'lighter_background' => ['--dim'],
		'foreground' => ['--fg'],
		'accent' => ['--ad-accent'],
		'selection' => ['--lit'],
		'red' => ['--ad-danger'],
		'green' => ['--ad-ok'],
	];

	/** The current theme's declarations for :root, or nothing when there is no theme to read. */
	static function current(string $appearance): string {
		$colors = self::read();
		return $colors !== null ? self::css($colors, $appearance) : '';
	}

	/** The current theme's scheme, which Auto follows here rather than the system's: Theme hands it to
	* adminer as a Light or Dark override would be, so its dark.css and the icons go with it. Null
	* without a theme that sets any colour. */
	static function currentMode(): ?string {
		$colors = self::read();
		return $colors !== null && self::css($colors, 'auto') !== '' ? self::mode($colors) : null;
	}

	private static function read(): ?string {
		$home = getenv('HOME');
		$colors = $home !== false ? @file_get_contents("$home/.local/state/omarchy/current/theme/colors.toml") : false;
		return $colors !== false ? $colors : null;
	}

	/** ponytail: dark without a mode line; a theme from before it marks light with a light.mode file. */
	private static function mode(string $toml): string {
		return preg_match('~^mode\s*=\s*"light"\s*$~m', $toml) ? 'light' : 'dark';
	}

	/** A theme comes from any git repository and this lands in a <style>, so a value is a hex colour
	* or it is dropped. A theme is one scheme, so Light or Dark pinned to the other side wins and leaves
	* the page out of the theme.
	* @param string $toml the contents of colors.toml
	* @param string $appearance the appearance preference: auto, light or dark
	*/
	static function css(string $toml, string $appearance): string {
		if ($appearance !== 'auto' && $appearance !== self::mode($toml)) {
			return '';
		}
		preg_match_all('~^(\w+)\s*=\s*"(#[0-9a-fA-F]{6})"\s*$~m', $toml, $matches);
		$colors = array_combine($matches[1], $matches[2]);
		$css = '';
		foreach (self::TOKENS as $key => $tokens) {
			foreach (isset($colors[$key]) ? $tokens : [] as $token) {
				$css .= "$token:{$colors[$key]};";
			}
		}
		return $css;
	}
}
