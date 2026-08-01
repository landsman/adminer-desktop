<?php
declare(strict_types=1);

/** Browser end-to-end check for sorting the data list in place.
 *
 * Clicks a column heading and confirms the rows came back in a different order, that the
 * document was never rebuilt to do it — the whole point — that the url says what is on screen
 * so a reload agrees with it, and that the values arriving are highlighted like the ones they
 * replaced.
 *
 * Run via `make e2e` (tests/e2e/run.php runs it), or on its own with
 * ./bin/frankenphp php-cli tests/e2e/table-sort.test.php.
 */

require __DIR__ . '/fixture.php';

use Playwright\Playwright;

$fix = e2e_boot();
$failures = [];

// documents is seeded out of id order by title, so sorting by it visibly reorders the rows.
$select = str_replace('select=users', 'select=documents', $fix['select']);

$measure = /** @lang JavaScript */ "() => ({
	first: (document.querySelector('#table tbody tr td:nth-child(3)')?.textContent ?? '').trim(),
	rows: document.querySelectorAll('#table tbody tr').length,
	highlighted: document.querySelectorAll('#table tbody code span[class^=jush]').length,
	// Set below and only ever lost by a new document, which is what this is here to catch.
	sameDocument: window.__adSameDocument === true,
})";

try {
	$context = Playwright::chromium(['headless' => true]);
	$page = $context->newPage();
	$page->setViewportSize(1600, 900);

	e2e_login($page, $fix['base'], $fix['pgPort']);
	$page->goto($select);
	$page->waitForLoadState('networkidle');

	$page->evaluate("() => { window.__adSameDocument = true; }");
	$before = $page->evaluate($measure);

	// Sort by title — the second column, so its heading link is the second sort link.
	$page->evaluate("() => document.querySelectorAll('#table thead th a')[3].click()");
	$page->waitForURL('**order**');
	$after = $page->evaluate($measure);

	if (!$after['sameDocument']) {
		$failures[] = 'sorting rebuilt the page instead of swapping the rows';
	}
	if ($after['first'] === $before['first']) {
		$failures[] = "sorting did not reorder the rows (still '{$after['first']}' first)";
	}
	if ($after['rows'] !== $before['rows']) {
		$failures[] = sprintf('the swap changed the row count (%d, was %d)', $after['rows'], $before['rows']);
	}
	// The values that arrived are coloured like the ones they replaced.
	if ($after['highlighted'] < $before['highlighted']) {
		$failures[] = sprintf(
			'the sorted rows lost the syntax highlighting (%d spans, was %d)',
			$after['highlighted'],
			$before['highlighted'],
		);
	}
	// And the url says what is on screen, so a reload does not undo it.
	if (!str_contains($page->url(), 'order')) {
		$failures[] = 'the url was not corrected to the sorted query: ' . $page->url();
	}
	$page->goto($page->url());
	$page->waitForLoadState('networkidle');
	$reloaded = $page->evaluate($measure);
	if ($reloaded['first'] !== $after['first']) {
		$failures[] = "a reload lost the sort ('{$reloaded['first']}', not '{$after['first']}')";
	}

	$page->screenshot($fix['shots'] . '/table-sort.png');
	$context->close();
} catch (\Throwable $e) {
	$failures[] = 'table-sort: ' . $e->getMessage();
}

e2e_done($fix['server'], $failures, 'table-sort');
