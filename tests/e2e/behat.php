<?php
declare(strict_types=1);

// One suite per driver, so `make e2e` covers both in one run.
//
// What decides where a feature goes is whether the driver could change the answer:
//
//   features/data/   the data list and the edit form — Adminer talking to a database through us.
//                    A column type, a JSON value or a foreign key reads differently per driver, so
//                    these run against every one of them.
//   features/shell/  the desktop's own chrome: the theme, the settings dialog, the sidebar, the
//                    import dropzone, the MCP endpoint. None of it asks the database anything, so a
//                    second suite would spend a second browser proving the same CSS. pgsql only.
//
//   features/pgsql/  what only one driver can answer: the plugins on a form, whose effect a real
//   features/mysql/  jsonb column would hide on another driver, or the raw bytes only mysqli hands
//                    the MCP tools. Same rule, one step further.
//
// The driver is handed to the contexts; each suite serves the app on a port of its own, because
// the suites share a process (harness/fixture.php picks the next free one from there).
//
// The contexts are autoloaded by composer, not by Behat's own loader: app/composer.json maps
// Desktop\Tests\ to bootstrap/ under autoload-dev, so they resolve the same way every other class
// in this repository does — and phpstan and the IDE see them too.

use Behat\Config\Config;
use Behat\Config\Formatter\PrettyFormatter;
use Behat\Config\Profile;
use Behat\Config\Suite;
use Desktop\Tests\DesktopContext;
use Desktop\Tests\McpContext;

return (new Config())->withProfile(
	(new Profile('default'))
		->withSuite(
			(new Suite('pgsql'))
				->withPaths(__DIR__ . '/features/data', __DIR__ . '/features/shell', __DIR__ . '/features/pgsql')
				->addContext(DesktopContext::class, ['driver' => 'pgsql'])
				->addContext(McpContext::class, ['driver' => 'pgsql']),
		)
		->withSuite(
			(new Suite('mysql'))
				->withPaths(__DIR__ . '/features/data', __DIR__ . '/features/mysql')
				->addContext(DesktopContext::class, ['driver' => 'mysql'])
				->addContext(McpContext::class, ['driver' => 'mysql']),
		)
		// The steps all live in three files, so naming them after each step says nothing.
		->withFormatter(new PrettyFormatter(paths: false)),
);
