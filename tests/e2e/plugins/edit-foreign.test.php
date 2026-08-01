<?php
declare(strict_types=1);

/** Browser end-to-end check that a plugin's constructor argument reaches the plugin.
 *
 * check.sh proves every shipped plugin boots, but only on the login page — it never sees what a
 * plugin does to a form, so the value in PluginList::ARGUMENTS could be ignored and nothing
 * would say so. AdminerEditForeign is the one that carries an argument today: it replaces a
 * foreign key's input with a <select> of the referenced table's rows, and upstream's default
 * limit of 0 means no LIMIT at all. We pass 100, and past it the plugin returns nothing so
 * Adminer's plain input stands.
 *
 * So both sides are the check: orders.user_id (six users) has to become a dropdown, and
 * big_child.lookup_id (150 rows) has to stay an input. A dropdown there would mean the
 * argument never arrived and every edit form on a big table reads it whole.
 *
 * Run via `make e2e` (tests/e2e/run.php runs it), or on its own with
 * ./bin/frankenphp php-cli tests/e2e/plugins/edit-foreign.test.php.
 */

require dirname(__DIR__) . '/fixture.php';

use Playwright\Playwright;

$fix = e2e_boot();
$failures = [];

// Straight to settings.json: the POST path that writes it is check.sh's business, and this
// check is about what the plugin does once it is on.
file_put_contents($fix['data'] . '/settings.json', json_encode(['plugins' => ['edit-foreign' => true]]));

$edit = fn(string $table): string => "{$fix['base']}?" . http_build_query([
	'pgsql' => "127.0.0.1:{$fix['pgPort']}",
	'username' => 'postgres',
	'db' => 'demo',
	'ns' => 'public',
	'edit' => $table,
	'where[id]' => 1,
]);

try {
	$context = Playwright::chromium(['headless' => true]);
	$page = $context->newPage();

	e2e_login($page, $fix['base'], $fix['pgPort']);

	// [table, field, has to be a dropdown, why]
	$cases = [
		['orders', 'user_id', true, 'six rows in users, well under the limit'],
		['big_child', 'lookup_id', false, '150 rows in big_lookup, over the limit'],
	];
	foreach ($cases as [$table, $field, $wantSelect, $why]) {
		$page->goto($edit($table));
		$page->waitForLoadState('networkidle');
		$tag = $page->evaluate("() => {
			const el = document.querySelector('form [name=\"fields[$field]\"]');
			return el ? el.tagName : null;
		}");
		$isSelect = ($tag === 'SELECT');
		if ($tag === null) {
			$failures[] = "edit-foreign: $table.$field is not on the edit form at all";
		} elseif ($isSelect !== $wantSelect) {
			$failures[] = $wantSelect
				? "edit-foreign: $table.$field stayed a $tag — the plugin is not applying ($why)"
				: "edit-foreign: $table.$field became a dropdown — the limit did not arrive, so the whole table was read ($why)";
		}
		$page->screenshot($fix['shots'] . "/plugins-edit-foreign-$table.png");
	}
} catch (Throwable $e) {
	$failures[] = 'edit-foreign: ' . $e->getMessage();
}

@unlink($fix['data'] . '/settings.json');
e2e_done($fix['server'], $failures, 'edit-foreign');
