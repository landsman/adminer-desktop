<?php
namespace Desktop;

/** Loads the stylesheet adminer-desktop adds on top of Adminer's own.
*
* Lives beside the CSS it loads rather than under settings/, because the styles are the
* app's, not the settings dialog's -- the dialog is just the only thing using them yet.
*/
class Styles {
	/** @var string */ private $dir;

	function __construct(string $dir) {
		$this->dir = $dir;
	}

	/** Print the <link>. Called from the head() hook, so it lands in <head> rather than
	* mid-body where a stylesheet would still work but has no business being.
	*/
	function link(): void {
		echo "<link rel='stylesheet' href='styles/desktop.css?v=" . $this->version() . "'>\n";
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
