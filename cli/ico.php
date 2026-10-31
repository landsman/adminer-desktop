<?php
declare(strict_types=1);

/** Packs PNG files into an .ico: a directory of sizes, each entry the PNG as it is, which every
* Windows since Vista reads. Usage: ico.php out.ico a.png b.png ...
*/

[, $out] = $_SERVER["argv"];
$files = array_slice($_SERVER["argv"], 2);

$directory = pack("vvv", 0, 1, count($files));
$images = "";
$offset = 6 + 16 * count($files);
foreach ($files as $file) {
	$png = (string) file_get_contents($file);
	if (!str_starts_with($png, "\x89PNG")) {
		fwrite(STDERR, "ico: $file is not a PNG\n");
		exit(1);
	}
	// A PNG's width and height are big-endian at byte 16 of its IHDR.
	$size = unpack("Nwidth/Nheight", $png, 16);
	// A byte each, so 256 is written as 0: the format's own way of saying it.
	$directory .= pack("CCCCvvVV", $size["width"] % 256, $size["height"] % 256, 0, 0, 1, 32, strlen($png), $offset);
	$images .= $png;
	$offset += strlen($png);
}
file_put_contents($out, $directory . $images);
