<?php
declare(strict_types=1);

namespace Desktop\Assets;

/** Loads the stylesheet adminer-desktop adds on top of Adminer's own.
*
* An asset loader, so it lives under Assets/ beside Javascript rather than under Settings/
* -- the settings dialog is just the only thing using the styles yet. The CSS sits in css/
* beside this file so the stylesheets are not interleaved with the code loading them.
*/
class Styles {
	private string $dir;

	function __construct(string $dir) {
		$this->dir = $dir;
	}

	/** Print the <link>. Called from the head() hook, so it lands in <head> rather than
	* mid-body where a stylesheet would still work but has no business being.
	*/
	function link(): void {
		echo "<link rel='stylesheet' href='src/Assets/css/desktop.css?v=" . $this->version() . "'>\n";
	}

	/** Cache-buster: the newest mtime across every stylesheet.
	*
	* desktop.css only @imports the others, so its own mtime says nothing about whether
	* the styles changed -- editing settings.css would leave a stale file cached.
	*/
	private function version(): int {
		$return = 0;
		foreach (glob($this->dir . "/*.css") as $filename) {
			$return = max($return, (int) @filemtime($filename));
		}
		return $return;
	}
}
