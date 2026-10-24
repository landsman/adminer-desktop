<?php
declare(strict_types=1);

// Writes launcher/mcpbridge/data.json: everything the Go bridge says that PHP owns.
//
// The bridge is a second implementation of the same offline answers the PHP one gives
// (app/src/Mcp/Stdio.php), and the two must not drift. So it owns no list of its own: this runs
// at build time, asks Server::catalogue() for each settings combination and Catalog for the
// translated sentences, and the launcher embeds the result. Adding a tool or a language is a
// change in the PHP source, and the Go side follows with no second copy to remember.
//
// `make build` and `make qa` run it before go compiles anything, and the output is gitignored —
// a stale copy in the repo would be exactly the drift this exists to prevent.

require dirname(__DIR__) . "/app/vendor/autoload.php";

use Desktop\I18n\Catalog;
use Desktop\I18n\Domain;
use Desktop\Mcp\Server;

// whole_values and writes are the two settings a description follows, so four catalogues.
$catalogues = [];
foreach ([false, true] as $whole) {
	foreach ([false, true] as $write) {
		$catalogues[($whole ? '1' : '0') . ($write ? '1' : '0')] = Server::catalogue($whole, $write);
	}
}

// Only the sentences the bridge itself can say. The rest need Adminer behind them and are never
// reached from Go.
$sentences = [];
foreach (Catalog::load(Domain::Plugin)->all() as $locale => $strings) {
	$sentences[$locale] = array_filter(
		$strings,
		static fn (string $id): bool => str_starts_with($id, 'mcp.agent_'),
		ARRAY_FILTER_USE_KEY,
	);
}

$data = [
	'protocolVersion' => Server::PROTOCOL,
	'instructions' => Server::INSTRUCTIONS,
	'catalogue' => $catalogues,
	'sentences' => $sentences,
];

$path = dirname(__DIR__) . '/launcher/mcpbridge/data.json';
$json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
if (file_put_contents($path, $json . "\n") === false) {
	fwrite(STDERR, "mcp bridge: could not write $path\n");
	exit(1);
}
echo "mcp bridge: wrote $path\n";
