<?php
declare(strict_types=1);

// The one entry the Makefile calls for build tooling: `$(CLI) <command>`.

use Desktop\Cli\Command\I18n;
use Desktop\Cli\Command\LattePrecompile;
use Desktop\Cli\Command\WindowsIco;

require dirname(__DIR__) . "/vendor/autoload.php";

$commands = [
	"i18n" => I18n::run(...),
	"i18n-check" => I18n::check(...),
	"latte-precompile" => LattePrecompile::run(...),
	"windows-ico" => WindowsIco::run(...),
];

$command = $commands[$argv[1] ?? ""] ?? null;
if (!$command) {
	fwrite(STDERR, "usage: run.php " . implode("|", array_keys($commands)) . "\n");
	exit(2);
}
$command();
