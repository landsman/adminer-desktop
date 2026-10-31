<?php
declare(strict_types=1);

/** Does the .ico say what Windows reads: how many images, how big, and where each one starts? A
 * wrong offset still builds, and shows up only as a blank icon on a machine nobody tests on.
 */

use Desktop\Cli\Command\WindowsIco;
use Tester\Assert;

require dirname(__DIR__) . "/bootstrap.php";

$small = "\x89PNG-small";
$large = "\x89PNG-a-larger-one";
$ico = WindowsIco::pack([16 => $small, 256 => $large]);

Assert::same(['reserved' => 0, 'type' => 1, 'count' => 2], unpack("vreserved/vtype/vcount", $ico));

$first = unpack("Cwidth/Cheight/x2/vplanes/vbits/Vlength/Voffset", $ico, 6);
Assert::same(16, $first['width']);
Assert::same(1, $first['planes']);
Assert::same(32, $first['bits']);
Assert::same($small, substr($ico, $first['offset'], $first['length']));

$second = unpack("Cwidth/Cheight/x2/vplanes/vbits/Vlength/Voffset", $ico, 22);
Assert::same(0, $second['width']); // 256 does not fit a byte
Assert::same(0, $second['height']);
Assert::same($large, substr($ico, $second['offset'], $second['length']));

Assert::exception(fn() => WindowsIco::pack([16 => '']), RuntimeException::class, 'ico: the 16 px image is not a PNG');

// A transparent corner stays transparent, or the icon sits on a black square.
$source = imagecreatetruecolor(64, 64);
imagealphablending($source, false);
imagefill($source, 0, 0, imagecolorallocatealpha($source, 0, 0, 0, 127));
$small = imagecreatefromstring(WindowsIco::resize($source, 16));
Assert::same(16, imagesx($small));
Assert::same(127, imagecolorat($small, 0, 0) >> 24);
