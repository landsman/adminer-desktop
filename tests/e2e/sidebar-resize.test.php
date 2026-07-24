<?php
declare(strict_types=1);

/** Browser end-to-end check for the resizable sidebar (issue #11).
 *
 * Logs in, drags the handle between the panels and confirms the sidebar actually widens,
 * that the width is persisted to the durable config, and that a fresh page load opens at
 * that stored width before any script runs — the cold-start path head() drives. Also nudges
 * it with the keyboard, the accessible way to move a splitter.
 *
 * Run via `make e2e` (tests/e2e/run.php runs it), or on its own with
 * ./bin/frankenphp php-cli tests/e2e/sidebar-resize.test.php.
 */

require __DIR__ . '/fixture.php';

use Playwright\Playwright;

$fix = e2e_boot();
$failures = [];

// The fixture's data dir, where Desktop\Config writes config.json. Start from a clean slate
// so a width left by an earlier run cannot push this drag into the clamp and mask a failure.
$config = sys_get_temp_dir() . '/adminer-desktop-e2e/config.json';
@unlink($config);

/** Poll for the beacon to land: sendBeacon is fire-and-forget, so the file appears a beat
 * after mouseup with no response to await. Returns the stored width, or null if it never
 * showed. */
$storedWidth = static function () use ($config): ?int {
	for ($i = 0; $i < 30; $i++) {
		if (is_file($config)) {
			$data = json_decode((string) file_get_contents($config), true);
			if (is_array($data) && isset($data['sidebar_width'])) {
				return (int) $data['sidebar_width'];
			}
		}
		usleep(100_000);
	}
	return null;
};

try {
	$context = Playwright::chromium(['headless' => true]);
	$page = $context->newPage();

	e2e_login($page, $fix['base'], $fix['pgPort']);
	$page->goto($fix['select']);
	$page->waitForLoadState('networkidle');

	// The handle only exists under the islands layout; its absence is the first failure.
	$rect = $page->evaluate("() => {
		const h = document.querySelector('#ad-sidebar-resizer');
		if (!h) { return null; }
		const r = h.getBoundingClientRect();
		return { x: r.x + r.width / 2, y: r.y + r.height / 2 };
	}");
	if (!is_array($rect)) {
		$failures[] = 'the resize handle was not inserted';
		e2e_done($fix['server'], $failures, 'sidebar-resize');
	}

	$footWidth = static fn () => (float) $page->evaluate(
		"() => document.querySelector('#foot').getBoundingClientRect().width",
	);
	$before = $footWidth();

	// Drag the handle 120px to the right; the sidebar should follow it.
	$mouse = $page->mouse();
	$mouse->move($rect['x'], $rect['y']);
	$mouse->down();
	$mouse->move($rect['x'] + 120, $rect['y'], ['steps' => 10]);
	$mouse->up();

	$after = $footWidth();
	if ($after - $before < 90) {
		$failures[] = sprintf('the drag did not widen the sidebar (%.0f -> %.0f)', $before, $after);
	}

	// The dragged width is stored, matching what the panel actually renders at.
	$stored = $storedWidth();
	if ($stored === null) {
		$failures[] = 'the width was not persisted to config.json';
	} elseif (abs($stored - $after) > 3) {
		$failures[] = sprintf('the stored width %d does not match the rendered %.0f', $stored, $after);
	}

	// Cold start: a fresh page must open at the stored width before any drag — head() emits
	// it into the initial HTML, so the property is already set on load.
	if ($stored !== null) {
		$cold = $context->newPage();
		$cold->goto($fix['select']);
		$cold->waitForLoadState('networkidle');
		$coldWidth = (float) $cold->evaluate("() => document.querySelector('#foot').getBoundingClientRect().width");
		if (abs($coldWidth - $stored) > 3) {
			$failures[] = sprintf('cold start opened at %.0f, not the stored %d', $coldWidth, $stored);
		}
		$cold->close();
	}

	// Keyboard: focus the splitter and nudge it narrower; the accessible path must move it too.
	$page->locator('#ad-sidebar-resizer')->focus();
	$wide = $footWidth();
	for ($i = 0; $i < 5; $i++) {
		$page->keyboard()->press('ArrowLeft');
	}
	if ($footWidth() >= $wide) {
		$failures[] = 'ArrowLeft did not narrow the sidebar';
	}

	$context->close();
} catch (\Throwable $e) {
	$failures[] = 'sidebar-resize: ' . $e->getMessage();
}

e2e_done($fix['server'], $failures, 'sidebar-resize');
