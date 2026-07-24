<?php
declare(strict_types=1);

/** Browser end-to-end check for the Adminer Desktop theme.
 *
 * Logs in and confirms the theme is actually applied in both light and dark, that the
 * scheme is emulated, and that the settings gear scrolls with the sidebar. Leaves
 * screenshots in tests/e2e/screenshots/.
 *
 * Run via `make e2e` (tests/e2e/run.php runs it), or on its own with
 * ./bin/frankenphp php-cli tests/e2e/theme.test.php.
 */

require __DIR__ . '/fixture.php';

use Playwright\Playwright;

$fix = e2e_boot();
$failures = [];

try {
	foreach (['light', 'dark'] as $scheme) {
		// colorScheme is a context option, so it goes under 'context' — passed at the top
		// level (next to the launch option 'headless') it is silently ignored.
		$options = ['headless' => true];
		if ($scheme === 'dark') {
			$options['context'] = ['colorScheme' => 'dark'];
		}
		$context = Playwright::chromium($options);
		$page = $context->newPage();

		e2e_login($page, $fix['base'], $fix['pgPort']);
		$page->goto($fix['select']);
		$page->waitForLoadState('networkidle');
		$page->screenshot($fix['shots'] . "/users-$scheme.png");

		$title = $page->title();
		if (!str_contains($title, 'users')) {
			$failures[] = "$scheme: not logged in (title: $title)";
		}
		// The theme's own token is only defined by our stylesheet, so a non-empty value
		// proves the Adminer Desktop CSS actually loaded and applied — not just that a page
		// rendered.
		$accent = $page->evaluate("() => getComputedStyle(document.documentElement).getPropertyValue('--ad-accent').trim()");
		if (!is_string($accent) || $accent === '') {
			$failures[] = "$scheme: theme not applied (--ad-accent is empty)";
		}
		// Both schemes are one set of light-dark() tokens now, resolved by color-scheme, so
		// assert a real surface actually resolved to this scheme's side. A non-empty token
		// alone would pass even if resolution silently fell back to light on every run.
		$bgIsDark = (bool) $page->evaluate("() => {
			const el = document.querySelector('#content') || document.body;
			const [r, g, b] = getComputedStyle(el).backgroundColor.match(/\\d+/g).map(Number);
			return r + g + b < 200;
		}");
		if ($bgIsDark !== ($scheme === 'dark')) {
			$failures[] = "$scheme: the surface did not resolve to the $scheme scheme";
		}
		// And that the scheme itself was emulated — otherwise a dark run silently renders
		// light and the screenshot is the only tell.
		$isDark = (bool) $page->evaluate("() => matchMedia('(prefers-color-scheme: dark)').matches");
		if ($isDark !== ($scheme === 'dark')) {
			$failures[] = "$scheme: prefers-color-scheme was not emulated";
		}
		// The gear sits in the sidebar's scroll flow, by the logo. position: fixed would
		// leave it hanging over the panel while everything it belongs to scrolls away.
		$moved = $page->evaluate("() => {
			const menu = document.querySelector('#menu'), gear = document.querySelector('#desktop-gear');
			const top = gear.getBoundingClientRect().top;
			menu.scrollTop = 200;
			return top - gear.getBoundingClientRect().top;
		}");
		if ($moved < 150) {
			$failures[] = "$scheme: the settings gear did not scroll with the sidebar (moved {$moved}px)";
		}

		$context->close();
	}
} catch (\Throwable $e) {
	$failures[] = 'theme: ' . $e->getMessage();
}

e2e_done($fix['server'], $failures, 'theme');
