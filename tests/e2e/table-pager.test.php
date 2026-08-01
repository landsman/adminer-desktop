<?php
declare(strict_types=1);

/** Browser end-to-end check for the pager.
 *
 * Confirms adminer's run of numbered links arrives as first/previous/page/of/next/last, that
 * the ends lead nowhere on the first page, that the arrow and the page list both move the rows
 * — in place, without the document being rebuilt — and that the pager itself follows.
 *
 * Run via `make e2e` (tests/e2e/run.php runs it), or on its own with
 * ./bin/frankenphp php-cli tests/e2e/table-pager.test.php.
 */

require __DIR__ . '/fixture.php';

use Playwright\Playwright;

$fix = e2e_boot();
$failures = [];

// Fifty rows, five to a page: ten pages.
$select = str_replace('select=users', 'select=documents&limit=5', $fix['select']);

$measure = /** @lang JavaScript */ "() => {
	const steps = [...document.querySelectorAll('.ad-page-step')];
	const list = document.querySelector('.ad-page-select');
	return {
		steps: steps.length,
		// A step with nowhere to go is a <span>, so it neither invites a click nor moves the row.
		ends: steps.filter((s) => s.tagName !== 'A').map((s) => s.textContent),
		pages: list ? list.options.length : 0,
		at: list ? list.value : '',
		total: (document.querySelector('.ad-page-total')?.textContent ?? '').trim(),
		// What the chip reads: the rows this page holds, as adminer numbers them.
		range: (list?.selectedOptions[0]?.textContent ?? '').trim(),
		// Each mark is an icon file, masked so it takes the row's colour. A path that stopped
		// resolving would leave the buttons blank and everything else here still passing.
		drawn: steps.filter((s) => (getComputedStyle(s, '::before').maskImage || '').includes('icons/')).length,
		chevron: (getComputedStyle(document.querySelector('.ad-page-chip'), '::after').maskImage || '').includes('icons/'),
		firstRow: (document.querySelector('#table tbody tr td:nth-child(3)')?.textContent ?? '').trim(),
		page: location.search.match(/[?&]page=(\\d+)/)?.[1] ?? '0',
		// Only a new document loses this, which is the thing paging is not supposed to do.
		sameDocument: window.__adSameDocument === true,
	};
}";

try {
	$context = Playwright::chromium(['headless' => true]);
	$page = $context->newPage();
	$page->setViewportSize(1400, 800);

	e2e_login($page, $fix['base'], $fix['pgPort']);
	$page->goto($select);
	$page->waitForLoadState('networkidle');
	$page->evaluate("() => { window.__adSameDocument = true; }");

	/** Wait for the rows to change rather than for the url, which the swap corrects a beat
	 * later. */
	$wait = static function (array $before) use ($page, $measure): array {
		$now = $before;
		for ($i = 0; $i < 40 && $now['firstRow'] === $before['firstRow']; $i++) {
			usleep(100_000);
			$now = $page->evaluate($measure);
		}
		return $now;
	};

	$first = $page->evaluate($measure);
	if ($first['steps'] !== 4) {
		$failures[] = sprintf('the pager has %d step controls, not first/prev/next/last', $first['steps']);
		e2e_done($fix['server'], $failures, 'table-pager');
	}
	// Fifty rows, five to a page.
	if ($first['pages'] !== 10) {
		$failures[] = sprintf('the page list offers %d pages, not 10', $first['pages']);
	}
	// Fifty rows in the table, five to a page: the chip counts rows, not pages.
	if (!str_contains($first['total'], '50')) {
		$failures[] = "the count beside it reads '{$first['total']}', which is not the 50 rows";
	}
	if ($first['range'] !== '1-5') {
		$failures[] = "the first page reads '{$first['range']}', not the rows 1-5 it holds";
	}
	// Every mark is drawn from its own file, and so is the chip's chevron.
	if ($first['drawn'] !== 4 || !$first['chevron']) {
		$failures[] = sprintf('%d of 4 marks are drawn from icons/, chevron: %s', $first['drawn'], $first['chevron'] ? 'yes' : 'no');
	}
	// On page one there is no first and no previous.
	if (count($first['ends']) !== 2) {
		$failures[] = 'on the first page, first and previous still lead somewhere';
	}

	// The next arrow: rows move, and the document does not.
	$page->evaluate("() => [...document.querySelectorAll('a.ad-page-step')][0].click()");
	$next = $wait($first);
	if ($next['firstRow'] === $first['firstRow']) {
		$failures[] = 'the next arrow did not move the rows';
	}
	if (!$next['sameDocument']) {
		$failures[] = 'paging rebuilt the page instead of swapping the rows';
	}
	if ($next['page'] !== '1' || $next['at'] !== '1') {
		$failures[] = "after one step the url says page={$next['page']} and the list says {$next['at']}";
	}
	if ($next['range'] !== '6-10') {
		$failures[] = "after one step the chip reads '{$next['range']}', not the rows 6-10";
	}
	// And now both ends lead somewhere, since there is a page on either side.
	if ($next['ends'] !== []) {
		$failures[] = 'off the first page, an end control still leads nowhere: ' . implode(',', $next['ends']);
	}

	// The list: jump straight to the last page. By value, not by label — the option labelled 9
	// is page 8, and a bare string matches the label here.
	$page->evaluate("() => {
		const list = document.querySelector('.ad-page-select');
		list.value = '9';
		list.dispatchEvent(new Event('change'));
	}");
	$last = $wait($next);
	if ($last['page'] !== '9' || $last['firstRow'] === $next['firstRow']) {
		$failures[] = "picking page 10 left the url at page={$last['page']}";
	}
	if (!$last['sameDocument']) {
		$failures[] = 'the page list rebuilt the document';
	}

	$page->screenshot($fix['shots'] . '/table-pager.png');
	$context->close();
} catch (\Throwable $e) {
	$failures[] = 'table-pager: ' . $e->getMessage();
}

e2e_done($fix['server'], $failures, 'table-pager');
