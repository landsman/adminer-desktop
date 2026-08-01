<?php
declare(strict_types=1);

/** Browser end-to-end check for the two JSON plugins on the edit form.
 *
 * check.sh proves every shipped plugin boots on the login page; it never reaches a form, so a
 * plugin that had silently stopped applying would still pass it. These two only show on an edit
 * form and only for a value that parses as JSON, which is what documents (seed.sql) is there for.
 *
 * The column that proves either of them is `notes` — text holding JSON. Adminer gives a real
 * jsonb column its own jush-js editor and JUSH pretty-prints it in the browser, so `payload`
 * looks the same whether the plugin is there or not; these plugins sniff the value rather than
 * the column type, and `notes` is where only a plugin can be doing the work. `title` is plain
 * text they have to leave as Adminer's own textarea.
 *
 * AdminerPrettyJsonColumn replaces the input with a textarea holding the value re-encoded with
 * JSON_PRETTY_PRINT — the assertion is the jush-js class it puts there and that what is in it is
 * indented, not the one line postgres returned. AdminerJsonColumn instead echoes a table of the
 * keys and returns nothing, so Adminer's own input stays beside it (adminer.php:194 falls
 * through on an empty return); the assertion is that table and the keys in it.
 *
 * One plugin at a time: both hook editInput and the first non-empty return wins, so having both
 * on would only prove which one comes first in the catalogue.
 *
 * Everything read out of the page is a boolean, a count or a short string. Handing this
 * playwright binding a multi-kilobyte innerHTML gets null back instead, which reads exactly like
 * a missing element.
 *
 * Run via `make e2e` (tests/e2e/run.php runs it), or on its own with
 * ./bin/frankenphp php-cli tests/e2e/json-column.test.php.
 */

require __DIR__ . '/fixture.php';

use Playwright\Playwright;

$fix = e2e_boot();
$failures = [];

$edit = "{$fix['base']}?" . http_build_query([
	'pgsql' => "127.0.0.1:{$fix['pgPort']}",
	'username' => 'postgres',
	'db' => 'demo',
	'ns' => 'public',
	'edit' => 'documents',
	'where[id]' => 1,
]);

/** Turn one plugin on, alone, and open the edit form under it.
 *
 * Straight to settings.json: the POST path that writes it is check.sh's business, and this check
 * is about what the plugin does once it is on.
 */
$only = function (string $name) use ($fix, $edit): callable {
	file_put_contents($fix['data'] . '/settings.json', json_encode(['plugins' => [$name => true]]));
	return function ($page) use ($edit): void {
		$page->goto($edit);
		$page->waitForLoadState('networkidle');
	};
};

/** Evaluate an expression with `el` bound to a field's editor and `td` to the cell around it. */
$field = fn(string $name, string $expression): string => "() => {
	const el = document.querySelector('form [name=\"fields[$name]\"]');
	if (!el) { return 'MISSING'; }
	const td = el.closest('td');
	return String($expression);
}";

try {
	$context = Playwright::chromium(['headless' => true]);
	$page = $context->newPage();

	e2e_login($page, $fix['base'], $fix['pgPort']);

	// ── pretty-json-column: its own textarea, holding the value indented ──
	$only('pretty-json-column')($page);

	// [column, has to be taken over, why]
	foreach ([
		// payload passes with the plugin off too — Adminer marks a jsonb column jush-js itself,
		// so this line only catches the plugin turning it into something else. notes is the proof.
		['payload', true, 'jsonb'],
		['notes', true, 'text holding JSON — the plugin sniffs the value, not the column type'],
		['title', false, 'plain text, nothing for the plugin to do'],
	] as [$name, $wantOurs, $why]) {
		// The tag alone would say nothing — Adminer renders a text column as a textarea anyway.
		// The plugin's marker is the jush-js class on the one it builds.
		$marker = $page->evaluate($field($name, "el.tagName + (el.classList.contains('jush-js') ? '.jush-js' : '')"));
		if ($marker === 'MISSING') {
			$failures[] = "pretty-json-column: documents.$name is not on the edit form at all";
		} elseif (($marker === 'TEXTAREA.jush-js') !== $wantOurs) {
			$failures[] = $wantOurs
				? "pretty-json-column: documents.$name is a $marker — the plugin is not applying ($why)"
				: "pretty-json-column: documents.$name is a $marker — the plugin took over a value it should not have ($why)";
		}
	}

	// The point of the plugin, and what a lost json_encode flag would cost while the textarea
	// above still passed. On notes, because nothing but the plugin formats a text column.
	$notes = (string) $page->evaluate($field('notes', 'el.value'));
	if (!str_contains($notes, "\n    \"")) {
		$failures[] = 'pretty-json-column: documents.notes is not pretty-printed — ' . var_export(substr($notes, 0, 120), true);
	}
	if (!str_contains($notes, 'Dvořáková')) {
		$failures[] = 'pretty-json-column: documents.notes lost its unicode (JSON_UNESCAPED_UNICODE)';
	}
	$page->screenshot($fix['shots'] . '/plugins-pretty-json-column.png');

	// ── json-column: a table of the keys, echoed beside Adminer's own input ──
	$only('json-column')($page);

	// [column, the keys its table has to list]
	foreach ([
		['payload', 'customer,paid'],
		['notes', 'author,revision'],
	] as [$name, $wantKeys]) {
		$keys = $page->evaluate($field($name, "[...td.querySelectorAll('table > tbody > tr > th')].map(th => th.textContent).sort().join(',')"));
		if ($keys === 'MISSING') {
			$failures[] = "json-column: documents.$name is not on the edit form at all";
			continue;
		}
		foreach (explode(',', $wantKeys) as $key) {
			if (!str_contains((string) $keys, $key)) {
				$failures[] = "json-column: documents.$name has no $key in its table — the plugin is not applying (keys: " . var_export($keys, true) . ')';
			}
		}
	}
	if ($page->evaluate($field('title', "!!td.querySelector('table')")) === 'true') {
		$failures[] = 'json-column: documents.title got a table — the plugin took over a value that is not JSON';
	}
	$page->screenshot($fix['shots'] . '/plugins-json-column.png');
} catch (Throwable $e) {
	$failures[] = 'json-column: ' . $e->getMessage();
}

@unlink($fix['data'] . '/settings.json');
e2e_done($fix['server'], $failures, 'json-column');
