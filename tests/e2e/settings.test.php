<?php
declare(strict_types=1);

/** Browser end-to-end check for the settings dialog.
 *
 * Every change made in the dialog must survive Save. This is a regression guard: the
 * language <select> is relocated into the settings form for layout, and while it still
 * carried name="lang", Save posted lang too — Adminer's lang.inc.php treats any request
 * carrying lang as a language switch and redirects before handlePost applies the settings,
 * so nothing saved. Each block changes one thing and asserts it came back.
 *
 * Run via `make e2e` (tests/e2e/run.php runs it), or on its own with
 * ./bin/frankenphp php-cli tests/e2e/settings.test.php.
 */

require __DIR__ . '/fixture.php';

use Playwright\Playwright;

$fix = e2e_boot();
$failures = [];

/** Open the settings dialog and let showModal() settle — its contents are display:none
 * until the modal is actually open, so anything reaching inside races the animation. */
$openDialog = function ($page) {
	$page->locator('#desktop-gear')->click();
	usleep(300_000);
};

try {
	$context = Playwright::chromium(['headless' => true]);
	$page = $context->newPage();
	e2e_login($page, $fix['base'], $fix['pgPort']);
	$page->goto($fix['select']);
	$page->waitForLoadState('networkidle');

	// 1. Row density -> a body class the theme keys off.
	$openDialog($page);
	$page->locator('input[name="density"][value="compact"]')->check(['force' => true]);
	$page->locator('#desktop-save')->click();
	$page->waitForLoadState('networkidle');
	$body = (string) $page->evaluate("() => document.body.className");
	if (!str_contains($body, 'density-compact')) {
		$failures[] = "density: did not save (body class: $body)";
	}
	// And it landed in the persistent store, not just this session — settings.json is what
	// survives a cold start (issue #10) and what the debug panel reads.
	$settingsFile = sys_get_temp_dir() . '/adminer-desktop-e2e/settings.json';
	clearstatcache(true, $settingsFile);
	$stored = is_file($settingsFile) ? json_decode((string) file_get_contents($settingsFile), true) : [];
	if (!is_array($stored) || ($stored['density'] ?? null) !== 'compact') {
		$failures[] = 'density: not persisted to settings.json (got: ' . json_encode($stored) . ')';
	}

	// 2. A light design -> its stylesheet is linked. Whichever gallery design is offered
	// first, so this does not break when the catalogue changes.
	$openDialog($page);
	$design = $page->evaluate("() => { const r = [...document.querySelectorAll('input[name=design_light]')].find(x => x.value); return r ? r.value : null; }");
	if (!$design) {
		$failures[] = "design: no gallery design was offered to pick";
	} else {
		$page->locator("input[name=\"design_light\"][value=\"$design\"]")->check(['force' => true]);
		$page->locator('#desktop-save')->click();
		$page->waitForLoadState('networkidle');
		$linked = (bool) $page->evaluate("(d) => [...document.querySelectorAll('link[rel=stylesheet]')].some(l => (l.getAttribute('href') || '').includes(d))", $design);
		if (!$linked) {
			$failures[] = "design: chosen design ($design) did not save";
		}
	}

	// 3. A plugin -> ticking it writes the name into settings.json, unticking takes it out,
	// so the stored set is the assertion. The tick/untick is set on the checkbox directly:
	// what this guards is that Save persists it, not the browser's own checkbox toggle.
	// Enable then disable, so the working tree is left as it was found.
	// row-numbers specifically: it only numbers rows in a select, so it cannot change
	// anything this test looks at. Fall back to whatever is first if it is gone.
	// No need to open the dialog to read this — the panel is in the DOM either way.
	$plugin = $page->evaluate("() => {
		const pick = document.querySelector('input[name=\"plugins[]\"][value=\"row-numbers\"]')
			|| document.querySelector('input[name=\"plugins[]\"]');
		return pick ? pick.value : null;
	}");
	$stored = fn(): string => (string) @file_get_contents($fix['data'] . "/settings.json");
	if (!$plugin) {
		$failures[] = "plugins: none were offered to toggle";
	} else {
		$setPlugin = function (bool $on) use ($page, $plugin, $openDialog) {
			$openDialog($page);
			$checked = $on ? 'true' : 'false';
			$page->evaluate("() => { document.querySelector(\"input[name='plugins[]'][value='$plugin']\").checked = $checked; }");
			$page->locator('#desktop-save')->click();
			$page->waitForLoadState('networkidle');
		};
		$setPlugin(true);
		if (!str_contains($stored(), "\"$plugin\"")) {
			$failures[] = "plugins: enabling '$plugin' did not save (not in settings.json)";
		}
		$setPlugin(false);
		if (str_contains($stored(), "\"$plugin\"")) {
			$failures[] = "plugins: disabling '$plugin' did not save (still in settings.json)";
		}
	}

	// 4. The language switch -> its own onchange posts and reloads in the new language.
	// This is the control that broke Save; here it must still switch on its own, and the
	// saves above prove it no longer breaks the form it sits in.
	//
	// The onchange navigates without Playwright starting the click that would wait for it,
	// so poll <html lang> until the reload lands rather than racing it with one evaluate.
	$switchLang = function ($page, string $to) use ($openDialog): string {
		$openDialog($page);
		$page->locator('#desktop-lang-slot select')->selectOption($to);
		for ($i = 0; $i < 30; $i++) {
			usleep(200_000);
			try {
				$lang = (string) $page->evaluate("() => document.documentElement.lang");
			} catch (\Throwable $e) {
				continue; // mid-navigation; the context was torn down, try again
			}
			if ($lang === $to) {
				return $lang;
			}
		}
		return $lang ?? '';
	};
	$htmlLang = $switchLang($page, 'de');
	if ($htmlLang !== 'de') {
		$failures[] = "language: switch did not apply (html lang: $htmlLang)";
	}
	$switchLang($page, 'en'); // back to English, so a rerun starts where this one did

	// 5. Appearance override -> forcing Dark must pin the dark scheme even though this
	// context's OS is light (no colorScheme emulation here). Proves the whole path end to
	// end: the radio posts, cssMap hands adminer only the dark side, adminer's
	// color-scheme meta flips the theme's light-dark() tokens to dark. Reset to Sync with
	// OS after, so a rerun starts clean.
	$readSurface = "() => {
		const el = document.querySelector('#content') || document.body;
		const [r, g, b] = getComputedStyle(el).backgroundColor.match(/\\d+/g).map(Number);
		return r + g + b < 200; // dark surface?
	}";
	$osDark = (bool) $page->evaluate("() => matchMedia('(prefers-color-scheme: dark)').matches");
	$openDialog($page);
	$page->locator('input[name="appearance"][value="dark"]')->check(['force' => true]);
	$page->locator('#desktop-save')->click();
	$page->waitForLoadState('networkidle');
	$appBody = (string) $page->evaluate("() => document.body.className");
	$forcedDark = (bool) $page->evaluate($readSurface);
	if ($osDark) {
		$failures[] = "appearance: a light OS context is needed to prove the override";
	} elseif (!str_contains($appBody, 'theme-dark')) {
		$failures[] = "appearance: Dark did not save (body class: $appBody)";
	} elseif (!$forcedDark) {
		$failures[] = "appearance: Dark override did not render dark under a light OS";
	}
	$openDialog($page);
	$page->locator('input[name="appearance"][value="auto"]')->check(['force' => true]);
	$page->locator('#desktop-save')->click();
	$page->waitForLoadState('networkidle');

	$page->screenshot($fix['shots'] . '/settings.png');

	// 6. Reset to defaults -> the file goes, and the page comes back at the defaults. Last,
	// because it throws away everything the cases above saved. A dragged width goes in first:
	// the reset has to forget what the api stored too, not only the dialog's own fields.
	$page->evaluate("() => navigator.sendBeacon(window.desktopApi.resize, new URLSearchParams({what: 'sidebar', width: '420'}))");
	$openDialog($page);
	$page->locator('input[name="density"][value="compact"]')->check(['force' => true]);
	$page->locator('#desktop-save')->click();
	$page->waitForLoadState('networkidle');
	// Playwright dismisses a native confirm() by default, which would answer no; this is the
	// user pressing yes.
	$page->evaluate("() => { window.confirm = () => true; }");
	$openDialog($page);
	$page->locator('#desktop-reset')->click();
	$page->waitForLoadState('networkidle');
	usleep(300_000); // the redirect lands before the unlink is visible to this process
	clearstatcache(true, $settingsFile);
	if (is_file($settingsFile)) {
		$failures[] = 'reset: settings.json survived (' . (string) file_get_contents($settingsFile) . ')';
	}
	$resetBody = (string) $page->evaluate("() => document.body.className");
	if (!str_contains($resetBody, 'density-cozy')) {
		$failures[] = "reset: the page did not come back at the defaults (body class: $resetBody)";
	}

	$context->close();
} catch (\Throwable $e) {
	$failures[] = 'settings: ' . $e->getMessage();
}

e2e_done($fix['server'], $failures, 'settings');
