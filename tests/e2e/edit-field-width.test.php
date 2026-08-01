<?php
declare(strict_types=1);

/** Browser end-to-end check for the resizable edit form fields.
 *
 * Opens a seeded row, drags a field by its own native resize grip and confirms the width
 * lands on every field on the form rather than the one dragged — the JSON column's JUSH
 * <pre> included, which carries an inline width no stylesheet can reach — that it is
 * persisted to the durable config, and that a fresh load opens at it before any script runs,
 * the cold-start path head() drives.
 *
 * Run via `make e2e` (tests/e2e/run.php runs it), or on its own with
 * ./bin/frankenphp php-cli tests/e2e/edit-field-width.test.php.
 */

require __DIR__ . '/fixture.php';

use Playwright\Playwright;

$fix = e2e_boot();
$failures = [];

// The fixture's data dir, where Desktop\UserSettings writes settings.json. Start clean so a
// width left by an earlier run cannot push this drag into the clamp and mask it.
$settings = $fix['data'] . '/settings.json';
@unlink($settings);

/** Poll for the beacon to land: sendBeacon is fire-and-forget, so the file appears a beat
 * after mouseup with no response to await. Returns the stored width, or null if it never
 * showed. */
$storedWidth = static function () use ($settings): ?int {
	for ($i = 0; $i < 30; $i++) {
		if (is_file($settings)) {
			$data = json_decode((string) file_get_contents($settings), true);
			if (is_array($data) && isset($data['edit_field_width'])) {
				return (int) $data['edit_field_width'];
			}
		}
		usleep(100_000);
	}
	return null;
};

// One seeded row of documents: a text column and a jsonb one, so both kinds of field are on
// the form — the textarea and the <pre> JUSH swaps in for it.
$edit = $fix['base'] . '?' . http_build_query([
	'pgsql' => '127.0.0.1:' . $fix['pgPort'],
	'username' => 'postgres',
	'db' => 'demo',
	'ns' => 'public',
	'edit' => 'documents',
	'where[id]' => '1',
]);

/** The width of every field the user can see, and the grip of the first — its bottom-right
 * corner, where the browser puts the resize handle. The hidden textarea behind a JUSH <pre>
 * is skipped; it has no width to speak of. */
$script = /** @lang JavaScript */ "() => {
	const all = [...document.querySelectorAll('#form > table.layout textarea, #form > table.layout pre.jush')]
		.filter((el) => el.offsetWidth > 0);
	const r = all[0].getBoundingClientRect();
	return { widths: all.map((el) => el.getBoundingClientRect().width), grip: { x: r.right - 3, y: r.bottom - 3 } };
}";

try {
	$context = Playwright::chromium(['headless' => true]);
	$page = $context->newPage();
	// Wide enough that the drag below stays clear of the max-width the theme caps fields at.
	$page->setViewportSize(1600, 900);

	e2e_login($page, $fix['base'], $fix['pgPort']);
	$page->goto($edit);
	// JUSH swaps the json column's textarea for its <pre> once the page has settled, so the
	// first measurement has to wait for that, not just for the HTML.
	$page->waitForLoadState('networkidle');

	$before = $page->evaluate($script);
	if (count($before['widths']) < 2) {
		$failures[] = 'the edit form has fewer than two visible fields to resize';
		e2e_done($fix['server'], $failures, 'edit-field-width');
	}

	// Drag the grip 150px to the right — the field's own native resize, which is the handle.
	$mouse = $page->mouse();
	$mouse->move($before['grip']['x'], $before['grip']['y']);
	$mouse->down();
	$mouse->move($before['grip']['x'] + 150, $before['grip']['y'], ['steps' => 10]);
	$mouse->up();

	$after = $page->evaluate($script);
	if ($after['widths'][0] - $before['widths'][0] < 120) {
		$failures[] = sprintf('the drag did not widen the field (%.0f -> %.0f)', $before['widths'][0], $after['widths'][0]);
	}
	// The point of the property: the fields nobody touched moved with it.
	// A few px of slack: JUSH's <pre> carries its own border and padding outside the width.
	foreach ($after['widths'] as $i => $width) {
		if (abs($width - $after['widths'][0]) > 12) {
			$failures[] = sprintf('field %d stayed at %.0f, not the dragged %.0f', $i, $width, $after['widths'][0]);
		}
	}

	$stored = $storedWidth();
	if ($stored === null) {
		$failures[] = 'the width was not persisted to settings.json';
	} elseif (abs($stored - $after['widths'][0]) > 12) {
		$failures[] = sprintf('the stored width %d does not match the rendered %.0f', $stored, $after['widths'][0]);
	}

	// Cold start: a fresh page must open at the stored width — head() emits it into the
	// initial HTML, so the property is set before anything paints.
	if ($stored !== null) {
		$cold = $context->newPage();
		$cold->setViewportSize(1600, 900);
		$cold->goto($edit);
		$cold->waitForLoadState('networkidle');
		$coldWidth = (float) $cold->evaluate(/** @lang JavaScript */ "() => document.querySelector('#form > table.layout textarea').getBoundingClientRect().width");
		if (abs($coldWidth - $stored) > 12) {
			$failures[] = sprintf('cold start opened at %.0f, not the stored %d', $coldWidth, $stored);
		}
		$cold->close();
	}

	$page->screenshot($fix['shots'] . '/edit-field-width.png');
	$context->close();
} catch (\Throwable $e) {
	$failures[] = 'edit-field-width: ' . $e->getMessage();
}

e2e_done($fix['server'], $failures, 'edit-field-width');
