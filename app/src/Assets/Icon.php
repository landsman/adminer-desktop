<?php
declare(strict_types=1);

namespace Desktop\Assets;

/** Our icons that are files in icons/, loaded as an <img> and cached by the webview like any other
* asset: one case per file, so a typo is a type error and the whole set is this list.
*
* Mirrors Styles and Javascript: the files sit beside this, and each URL carries ?v=mtime, so an
* edited icon is never served stale.
*/
enum Icon: string {
	case Spinner = "spinner.svg";

	/** The URL to put in an <img src>, the same one a preload fetched. */
	function url(): string {
		return "src/Assets/icons/$this->value?v=" . (int) @filemtime(__DIR__ . "/icons/$this->value");
	}

	/** Whether every page preloads it: the ones needed at a moment nothing more can load. The
	* activity indicator's is shown as the page navigates away, and WKWebView fetches and draws
	* nothing more for a page by then. */
	function preloaded(): bool {
		return match ($this) {
			self::Spinner => true,
		};
	}

	/** Print a <link rel=preload> per preloaded icon. Called from the head() hook. */
	static function preload(): void {
		foreach (self::cases() as $icon) {
			if ($icon->preloaded()) {
				echo "<link rel='preload' href='" . $icon->url() . "' as='image'>\n";
			}
		}
	}
}
