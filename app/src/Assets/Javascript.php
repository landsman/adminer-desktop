<?php
declare(strict_types=1);

namespace Desktop\Assets;

/** Loads the JavaScript that makes the app behave like a browser rather than a bare
* WebView -- see javascript/README.md for what belongs there.
*
* Mirrors Styles: the assets sit in a folder beside this loader rather than interleaved
* with it, and are emitted with Adminer's own script_src() so the CSP nonce is honoured.
* Drop a .js in javascript/ and it loads; nothing here lists them by name.
*/
class Javascript {
	private string $dir;

	function __construct(string $dir) {
		$this->dir = $dir;
	}

	/** Print a <script src> per file in javascript/. Called from the head() hook. Adminer's
	* script_src() adds the CSP nonce the page's Content-Security-Policy requires under
	* 'strict-dynamic'; an external tag without it is blocked. defer keeps head parsing
	* unblocked -- none of these need to run before the document exists. The ?v=mtime is a
	* cache-buster, so an edited script is never served stale.
	*/
	function link(): void {
		foreach (glob($this->dir . "/*.js") as $filename) {
			$name = basename($filename);
			echo \Adminer\script_src("src/Assets/javascript/$name?v=" . (int) @filemtime($filename), true);
		}
	}

	/** Print a blocking <script src> for a file in javascript/before-paint/, from the head() hook
	* after the stylesheets: it runs before the body is parsed, so it can arrange the page as it
	* arrives and nothing is seen to move. Only for what would otherwise be visibly redrawn -- it
	* holds up parsing while it loads.
	*/
	function beforePaint(string $name): void {
		$filename = $this->dir . "/before-paint/$name";
		echo \Adminer\script_src("src/Assets/javascript/before-paint/$name?v=" . (int) @filemtime($filename));
	}
}
