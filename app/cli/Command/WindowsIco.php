<?php
declare(strict_types=1);

namespace Desktop\Cli\Command;

use GdImage;
use RuntimeException;

/** Builds the Windows icon from assets/logo.png, the one source every platform's icon comes from. */
class WindowsIco {
	private const array SIZES = [16, 24, 32, 48, 64, 128, 256];

	static function run(): void {
		$root = dirname(__DIR__, 3);
		$logo = imagecreatefrompng("$root/assets/logo.png");
		if (!$logo) {
			throw new RuntimeException("ico: cannot read assets/logo.png");
		}
		$images = [];
		foreach (self::SIZES as $size) {
			$images[$size] = self::resize($logo, $size);
		}
		file_put_contents("$root/build.noindex/logo.ico", self::pack($images));
	}

	/** The PNG bytes of a square copy, keeping the transparency around the logo. */
	static function resize(GdImage $source, int $size): string {
		$target = imagecreatetruecolor($size, $size);
		imagealphablending($target, false);
		imagesavealpha($target, true);
		imagecopyresampled($target, $source, 0, 0, 0, 0, $size, $size, imagesx($source), imagesy($source));
		ob_start();
		imagepng($target);
		return (string) ob_get_clean();
	}

	/** A directory of sizes, each entry the PNG as it is, which every Windows since Vista reads.
	* @param array<int, string> $images PNG bytes by width in pixels; they are square
	*/
	static function pack(array $images): string {
		$directory = pack("vvv", 0, 1, count($images));
		$body = "";
		$offset = 6 + 16 * count($images);
		foreach ($images as $size => $png) {
			if (!str_starts_with($png, "\x89PNG")) {
				throw new RuntimeException("ico: the $size px image is not a PNG");
			}
			// A byte each, so 256 is written as 0: the format's own way of saying it.
			$directory .= pack("CCCCvvVV", $size % 256, $size % 256, 0, 0, 1, 32, strlen($png), $offset);
			$body .= $png;
			$offset += strlen($png);
		}
		return $directory . $body;
	}
}
