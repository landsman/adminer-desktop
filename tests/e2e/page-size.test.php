<?php
declare(strict_types=1);

/** Browser end-to-end check for the rows-per-page picker.
 *
 * Confirms adminer's Limit field arrives as a list rather than a number to type, that the size
 * the page came with is in it and chosen, and that picking another one applies it — the rows
 * on screen and the field itself both say the new size, with no Select to press.
 *
 * Run via `make e2e` (tests/e2e/run.php runs it), or on its own with
 * ./bin/frankenphp php-cli tests/e2e/page-size.test.php.
 */

require __DIR__ . '/fixture.php';

use Playwright\Playwright;

$fix = e2e_boot();
$failures = [];

// Fifty rows, so a smaller page size visibly cuts the list.
$select = str_replace('select=users', 'select=documents', $fix['select']);

$measure = /** @lang JavaScript */ "() => {
	const field = document.querySelector(\"#form [name='limit']\");
	return {
		tag: field ? field.tagName : 'none',
		value: field ? field.value : '',
		options: field && field.options ? [...field.options].map((o) => o.value) : [],
		rows: document.querySelectorAll('#table tbody tr').length,
	};
}";

try {
	$context = Playwright::chromium(['headless' => true]);
	$page = $context->newPage();
	$page->setViewportSize(1400, 900);

	e2e_login($page, $fix['base'], $fix['pgPort']);
	$page->goto($select);
	$page->waitForLoadState('networkidle');

	$before = $page->evaluate($measure);
	if ($before['tag'] !== 'SELECT') {
		$failures[] = "Limit is still a {$before['tag']}, not a list to pick from";
		e2e_done($fix['server'], $failures, 'page-size');
	}
	// The size the page arrived with is the one showing, whether or not it is one of ours.
	if ($before['value'] !== '50') {
		$failures[] = "the list opened on '{$before['value']}', not the 50 the page was showing";
	}
	if (!in_array('50', $before['options'], true) || count($before['options']) < 5) {
		$failures[] = 'the sizes offered look wrong: ' . implode(',', $before['options']);
	}

	// Pick a smaller one: no Select to press, and the rows follow.
	$page->locator("#form select[name='limit']")->selectOption('10');
	$page->waitForURL('**limit=10**');
	$page->waitForLoadState('networkidle');
	$after = $page->evaluate($measure);

	if ($after['rows'] !== 10) {
		$failures[] = sprintf('picking 10 left %d rows on screen, not 10', $after['rows']);
	}
	if ($after['value'] !== '10') {
		$failures[] = "the list came back on '{$after['value']}', not the 10 that was picked";
	}

	$page->screenshot($fix['shots'] . '/page-size.png');
	$context->close();
} catch (\Throwable $e) {
	$failures[] = 'page-size: ' . $e->getMessage();
}

e2e_done($fix['server'], $failures, 'page-size');
