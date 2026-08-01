<?php
declare(strict_types=1);

/** Browser end-to-end check for the resizable data-list columns.
 *
 * Drags a column header's grip and confirms the column widened, that the ones beside it did
 * not pay for it, that the table grew and scrolls inside the content panel rather than the
 * window, and that a reload comes back to the same width — from sessionStorage, with nothing
 * written to settings.json, which is the whole point of where it is kept.
 *
 * Run via `make e2e` (tests/e2e/run.php runs it), or on its own with
 * ./bin/frankenphp php-cli tests/e2e/table-columns.test.php.
 */

require __DIR__ . '/fixture.php';

use Playwright\Playwright;

$fix = e2e_boot();
$failures = [];

$settings = $fix['data'] . '/settings.json';
@unlink($settings);

// documents has a json column wide enough to squeeze the others, which is the case this is for.
$select = str_replace('select=users', 'select=documents', $fix['select']);

/** Every header cell's width, plus where to grab the second column's right edge. */
$measure = /** @lang JavaScript */ "() => {
	const ths = [...document.querySelectorAll('#table tr:first-child th')];
	// The json column: the widest one, and the one the whole feature is for.
	const grip = ths[2].querySelector('.ad-column-grip');
	if (!grip) { return null; }
	const box = grip.getBoundingClientRect();
	const content = document.querySelector('#content');
	return {
		widths: ths.map((th) => Math.round(th.getBoundingClientRect().width)),
		// Deliberately not the header: the grip runs the height of the column, and grabbing it
		// beside a data row well below the header is the point of that.
		grip: { x: box.right - 1, y: box.top + Math.min(200, box.height - 10) },
		gripHeight: Math.round(box.height),
		// How far the grip runs past adminer's sticky row actions, which float over the last
		// rows — the list ends where they begin, margin included: that gap is the footer's own
		// background shadow, painted over the rows behind it.
		pastFooter: (() => {
			const footer = document.querySelector('.footer');
			const margin = Number.parseFloat(getComputedStyle(footer).marginTop);
			return Math.round(box.bottom - (footer.getBoundingClientRect().top - margin));
		})(),
		textLength: Number(document.querySelector(\"input[name='text_length']\").value),
		table: Math.round(document.querySelector('#table').getBoundingClientRect().width),
		contentScrolls: content.scrollWidth > content.clientWidth,
		windowScrolls: document.documentElement.scrollWidth > document.documentElement.clientWidth,
		checked: [...document.querySelectorAll('#table input[type=checkbox]')].filter((c) => c.checked).length,
	};
}";

try {
	$context = Playwright::chromium(['headless' => true]);
	$page = $context->newPage();
	$page->setViewportSize(1600, 900);

	e2e_login($page, $fix['base'], $fix['pgPort']);
	$page->goto($select);
	$page->waitForLoadState('networkidle');

	$before = $page->evaluate($measure);
	if (!is_array($before)) {
		$failures[] = 'no resize grip was added to the column headers';
		e2e_done($fix['server'], $failures, 'table-columns');
	}

	// Drag the json column 150px wider.
	$mouse = $page->mouse();
	$mouse->move($before['grip']['x'], $before['grip']['y']);
	$mouse->down();
	$mouse->move($before['grip']['x'] + 150, $before['grip']['y'], ['steps' => 10]);
	$mouse->up();

	$after = $page->evaluate($measure);
	if ($after['widths'][2] - $before['widths'][2] < 120) {
		$failures[] = sprintf('the drag did not widen the column (%d -> %d)', $before['widths'][2], $after['widths'][2]);
	}
	// The neighbours keep what they had: the table grows instead of them shrinking.
	foreach ([0, 1, 3] as $other) {
		if (abs($after['widths'][$other] - $before['widths'][$other]) > 2) {
			$failures[] = sprintf(
				'column %d moved with the drag (%d -> %d)',
				$other,
				$before['widths'][$other],
				$after['widths'][$other],
			);
		}
	}
	if ($after['table'] - $before['table'] < 120) {
		$failures[] = sprintf('the table did not grow with the column (%d -> %d)', $before['table'], $after['table']);
	}
	// And the wider table scrolls in the panel, not by pushing the whole window sideways.
	if (!$after['contentScrolls'] || $after['windowScrolls']) {
		$failures[] = 'the widened table did not scroll inside the content panel';
	}
	// The drag is not a click on the header: adminer's tableClick is bound to the table, and a
	// click reaching it from the grip ticks the header row's box, which is every row selected.
	if ($after['checked'] > 0) {
		$failures[] = sprintf('the drag selected rows (%d checkboxes ticked)', $after['checked']);
	}
	// The grip is the column's, not the header's — this drag was grabbed beside a data row, so
	// it only worked at all because of that, but the height says it plainly.
	if ($before['gripHeight'] < 200) {
		$failures[] = sprintf('the grip is only %dpx tall, not the column', $before['gripHeight']);
	}
	// And it stops there rather than running down over the buttons.
	if ($before['pastFooter'] > 1) {
		$failures[] = sprintf('the grip runs %dpx past the row actions', $before['pastFooter']);
	}
	// A wider column needs more text fetched into it, or it is wider whitespace. The number is
	// the column's width in its own characters, so it has to clear the width in the widest
	// plausible ones — a bad measurement (the checkbox column's font, say) reads as a pass at
	// 101, and this json column is a good deal more than that.
	if ($after['textLength'] < $after['widths'][2] / 12) {
		$failures[] = sprintf(
			'Text length %d is too small for a %dpx column',
			$after['textLength'],
			$after['widths'][2],
		);
	}
	if ($after['textLength'] <= $before['textLength']) {
		$failures[] = sprintf(
			'the widened column did not raise Text length (still %d)',
			$after['textLength'],
		);
	}

	// A reload keeps it: sessionStorage lives as long as the window does.
	$page->goto($select);
	$page->waitForLoadState('networkidle');
	$reloaded = $page->evaluate($measure);
	if (abs($reloaded['widths'][2] - $after['widths'][2]) > 2) {
		$failures[] = sprintf('the reload lost the width (%d, not %d)', $reloaded['widths'][2], $after['widths'][2]);
	}

	// But it is only the session's: nothing about columns reaches the durable file.
	clearstatcache(true, $settings);
	$stored = is_file($settings) ? (string) file_get_contents($settings) : '';
	if (str_contains($stored, 'column')) {
		$failures[] = "a column width reached settings.json: $stored";
	}

	$page->screenshot($fix['shots'] . '/table-columns.png');
	$context->close();
} catch (\Throwable $e) {
	$failures[] = 'table-columns: ' . $e->getMessage();
}

e2e_done($fix['server'], $failures, 'table-columns');
