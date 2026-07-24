<?php
declare(strict_types=1);

/** Browser end-to-end check for the whole-page import dropzone (drag-drop-import.js).
 *
 * Logs in, and first confirms the dropzone stays off every other page — on the users list its
 * overlay is never built. Then on Adminer's import page it drags a file over the window and
 * confirms the affordance appears, and that dropping it lands the file on Adminer's own
 * `sql_file[]` input (so Adminer's existing upload takes over) while the browser's own
 * navigate-to-file default is cancelled.
 *
 * Run via `make e2e` (tests/e2e/run.php runs it), or on its own with
 * ./bin/frankenphp php-cli tests/e2e/drag-drop-import.test.php.
 */

require __DIR__ . '/fixture.php';

use Playwright\Playwright;

$fix = e2e_boot();
$failures = [];

// The import page is the sql page in import mode: `import=` is what flips it (adminer.php sets
// $_GET["sql"]=$_GET["import"]), and that is the page carrying the sql_file[] upload.
$import = "{$fix['base']}?" . http_build_query([
	'pgsql' => "127.0.0.1:{$fix['pgPort']}",
	'username' => 'postgres',
	'db' => 'demo',
	'ns' => 'public',
	'import' => '',
]);

try {
	$context = Playwright::chromium(['headless' => true]);
	$page = $context->newPage();

	e2e_login($page, $fix['base'], $fix['pgPort']);

	// Scope: on a non-import page the script must do nothing, so the overlay is never created.
	$page->goto($fix['select']);
	$page->waitForLoadState('networkidle');
	$offImport = $page->evaluate("() => document.getElementById('ad-import-drop') === null");
	if ($offImport !== true) {
		$failures[] = 'the dropzone overlay leaked onto a page that is not the import page';
	}

	$page->goto($import);
	$page->waitForLoadState('networkidle');

	// The page the whole feature hangs off: Adminer's own upload input, and our overlay wired
	// up beside it, hidden until a drag begins.
	$ready = $page->evaluate("() => {
		const input = document.querySelector('input[type=\"file\"][name=\"sql_file[]\"]');
		const overlay = document.getElementById('ad-import-drop');
		return {
			input: !!input,
			overlay: !!overlay,
			hidden: overlay ? getComputedStyle(overlay).visibility === 'hidden' : null,
		};
	}");
	if (!is_array($ready) || !$ready['input']) {
		$failures[] = 'Adminer\'s sql_file[] upload input was not found on the import page';
	}
	if (!is_array($ready) || !$ready['overlay']) {
		$failures[] = 'the dropzone overlay was not created on the import page';
	} elseif ($ready['hidden'] !== true) {
		$failures[] = 'the dropzone overlay was visible before any drag';
	}

	// Drag a file over the window: dragenter + dragover must raise the affordance. The same
	// DataTransfer is kept on window so the drop below is the tail of one continuous drag.
	$hovering = $page->evaluate("() => {
		const dt = new DataTransfer();
		dt.items.add(new File(['SELECT 1;\\n'], 'dropped-dump.sql', { type: 'application/sql' }));
		window.__adDrag = dt;
		const fire = (t) =>
			window.dispatchEvent(new DragEvent(t, { bubbles: true, cancelable: true, dataTransfer: dt }));
		fire('dragenter');
		fire('dragover');
		const o = document.getElementById('ad-import-drop');
		return o.classList.contains('dragging') && getComputedStyle(o).visibility === 'visible';
	}");
	if ($hovering !== true) {
		$failures[] = 'dragging a file over the page did not raise the drop affordance';
	}
	// Proof of the affordance for a human to glance at, like the other checks leave behind.
	$page->screenshot($fix['shots'] . '/import-dropzone.png');

	// Drop it: the file must land on Adminer's input, the overlay must fall away, and the
	// browser's own "navigate to the file" default must be cancelled (dispatchEvent returns
	// false when a handler preventDefaults a cancelable event).
	$drop = $page->evaluate("() => {
		const dt = window.__adDrag;
		const notPrevented = window.dispatchEvent(
			new DragEvent('drop', { bubbles: true, cancelable: true, dataTransfer: dt }),
		);
		const input = document.querySelector('input[name=\"sql_file[]\"]');
		const o = document.getElementById('ad-import-drop');
		return {
			count: input.files.length,
			name: input.files.length ? input.files[0].name : null,
			prevented: !notPrevented,
			hidden: !o.classList.contains('dragging'),
		};
	}");
	if (!is_array($drop) || $drop['count'] !== 1 || $drop['name'] !== 'dropped-dump.sql') {
		$failures[] = 'the dropped file did not land on Adminer\'s sql_file[] input';
	}
	if (!is_array($drop) || $drop['prevented'] !== true) {
		$failures[] = 'the drop did not cancel the browser\'s navigate-to-file default';
	}
	if (!is_array($drop) || $drop['hidden'] !== true) {
		$failures[] = 'the overlay stayed up after the drop';
	}

	$context->close();
} catch (\Throwable $e) {
	$failures[] = 'drag-drop-import: ' . $e->getMessage();
}

e2e_done($fix['server'], $failures, 'drag-drop-import');
