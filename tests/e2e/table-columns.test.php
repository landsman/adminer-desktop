<?php
declare(strict_types=1);

/** Browser end-to-end check for the resizable data-list columns.
 *
 * Two drags, because they do different things. A narrow column first: the grip is grabbed
 * beside a data row rather than on the header, the column widens while its neighbours keep
 * what they had, the table grows and scrolls inside the panel, no row gets selected, and a
 * reload comes back to the same width — from sessionStorage, with nothing written to
 * settings.json, which is the whole point of where it is kept. Then the json column, whose
 * values were already cut to fit the width it had: that one has to raise Text length and
 * re-run the query, or the new space comes back empty.
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

/** Every header cell's width, and where to grab one column's right edge. */
$measure = /** @lang JavaScript */ "(column) => {
	const ths = [...document.querySelectorAll('#table tr:first-child th')];
	const grip = ths[column].querySelector('.ad-column-grip');
	if (!grip) { return null; }
	const box = grip.getBoundingClientRect();
	const content = document.querySelector('#content');
	const footer = document.querySelector('.footer');
	return {
		widths: ths.map((th) => Math.round(th.getBoundingClientRect().width)),
		// Deliberately not the header: the grip runs the height of the column, and grabbing it
		// beside a data row well below the header is the point of that.
		grip: { x: box.right - 1, y: box.top + Math.min(200, box.height - 10) },
		gripHeight: Math.round(box.height),
		// How far it runs past adminer's sticky row actions, which float over the last rows —
		// the list ends where they begin, margin included: that gap is the footer's own
		// background shadow, painted over the rows behind it.
		pastFooter: Math.round(
			box.bottom - (footer.getBoundingClientRect().top - Number.parseFloat(getComputedStyle(footer).marginTop))
		),
		textLength: Number(document.querySelector(\"input[name='text_length']\").value),
		table: Math.round(document.querySelector('#table').getBoundingClientRect().width),
		contentScrolls: content.scrollWidth > content.clientWidth,
		windowScrolls: document.documentElement.scrollWidth > document.documentElement.clientWidth,
		checked: [...document.querySelectorAll('#table input[type=checkbox]')].filter((c) => c.checked).length,
		// Highlighted values, so a swapped-in row is not plain text where the one it replaced
		// was coloured.
		highlighted: document.querySelectorAll('#table tbody code span.jush-js_val, #table tbody code span[class^=jush]').length,
		// The longest value on show: what raising Text length is actually for.
		longestValue: Math.max(...[...document.querySelectorAll('#table tbody tr')]
			.map((tr) => (tr.cells[column + 1]?.textContent ?? '').length)),
	};
}";

try {
	$context = Playwright::chromium(['headless' => true]);
	$page = $context->newPage();
	$page->setViewportSize(1600, 900);

	$drag = static function (array $at, int $by) use ($page): void {
		$mouse = $page->mouse();
		$mouse->move($at['x'], $at['y']);
		$mouse->down();
		$mouse->move($at['x'] + $by, $at['y'], ['steps' => 10]);
		$mouse->up();
	};

	e2e_login($page, $fix['base'], $fix['pgPort']);
	$page->goto($select);
	$page->waitForLoadState('networkidle');

	// 1. title: 150px wider is still fewer characters than Text length already allows, so this
	// is the resize on its own, with no query behind it.
	$before = $page->evaluate($measure, 1);
	if (!is_array($before)) {
		$failures[] = 'no resize grip was added to the column headers';
		e2e_done($fix['server'], $failures, 'table-columns');
	}
	$drag($before['grip'], 150);
	$after = $page->evaluate($measure, 1);

	if ($after['widths'][1] - $before['widths'][1] < 120) {
		$failures[] = sprintf('the drag did not widen the column (%d -> %d)', $before['widths'][1], $after['widths'][1]);
	}
	// The neighbours keep what they had: the table grows instead of them shrinking.
	foreach ([0, 2, 3] as $other) {
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
	// And it stops where the list does rather than running down over the buttons.
	if ($before['pastFooter'] > 1) {
		$failures[] = sprintf('the grip runs %dpx past the row actions', $before['pastFooter']);
	}
	// This much text already fits, so nothing is re-fetched and nothing reloads.
	if ($after['textLength'] !== $before['textLength']) {
		$failures[] = sprintf(
			'a column that already fits raised Text length anyway (%d -> %d)',
			$before['textLength'],
			$after['textLength'],
		);
	}

	// A reload keeps it: sessionStorage lives as long as the window does.
	$page->goto($select);
	$page->waitForLoadState('networkidle');
	$reloaded = $page->evaluate($measure, 1);
	if (abs($reloaded['widths'][1] - $after['widths'][1]) > 2) {
		$failures[] = sprintf('the reload lost the width (%d, not %d)', $reloaded['widths'][1], $after['widths'][1]);
	}

	// 2. payload: json, and already cut to fit the width it had. Widening it raises Text length
	// and runs the query again — in place, so there is a url to wait for but no new document.
	$wide = $page->evaluate($measure, 2);
	$drag($wide['grip'], 200);
	$page->waitForURL('**text_length=**');
	$refetched = $page->evaluate($measure, 2);

	if ($refetched['textLength'] <= $wide['textLength']) {
		$failures[] = sprintf('the widened json column did not raise Text length (still %d)', $refetched['textLength']);
	}
	// The number is the column's width in its own characters, so it has to clear that width in
	// the widest plausible ones — measuring the wrong column's font reads as a pass at 101.
	if ($refetched['textLength'] < $refetched['widths'][2] / 12) {
		$failures[] = sprintf(
			'Text length %d is too small for a %dpx column',
			$refetched['textLength'],
			$refetched['widths'][2],
		);
	}
	// Raising the number is no use unless the query runs again — and the proof of that is on
	// screen: the values in the widened column are longer than the ones it replaced.
	if ($refetched['longestValue'] <= $wide['longestValue']) {
		$failures[] = sprintf(
			'the re-run fetched no more text (longest value still %d characters)',
			$refetched['longestValue'],
		);
	}
	// The rows that arrived are highlighted like the ones they replaced — adminer colours the
	// values once at load, so anything swapped in afterwards is plain text unless asked.
	if ($refetched['highlighted'] < $wide['highlighted']) {
		$failures[] = sprintf(
			'the re-run lost the syntax highlighting (%d highlighted spans, was %d)',
			$refetched['highlighted'],
			$wide['highlighted'],
		);
	}
	// And the column that caused it comes back at the width it was dragged to.
	if (abs($refetched['widths'][2] - ($wide['widths'][2] + 200)) > 4) {
		$failures[] = sprintf(
			'the re-run lost the dragged width (%d, not %d)',
			$refetched['widths'][2],
			$wide['widths'][2] + 200,
		);
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
