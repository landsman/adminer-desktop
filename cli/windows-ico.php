<?php
declare(strict_types=1);

/** Packs the PNGs `make logo-ico` resized into assets/logo.ico: a directory of sizes, each entry
* the PNG as it is, which every Windows since Vista reads.
*/

const SIZES = [16, 24, 32, 48, 64, 128, 256];

$directory = pack("vvv", 0, 1, count(SIZES));
$images = "";
$offset = 6 + 16 * count(SIZES);
foreach (SIZES as $size) {
	$png = (string) file_get_contents(__DIR__ . "/../build.noindex/ico/$size.png");
	if (!str_starts_with($png, "\x89PNG")) {
		fwrite(STDERR, "ico: no $size px PNG\n");
		exit(1);
	}
	// A byte each, so 256 is written as 0: the format's own way of saying it.
	$directory .= pack("CCCCvvVV", $size % 256, $size % 256, 0, 0, 1, 32, strlen($png), $offset);
	$images .= $png;
	$offset += strlen($png);
}
file_put_contents(__DIR__ . "/../assets/logo.ico", $directory . $images);
