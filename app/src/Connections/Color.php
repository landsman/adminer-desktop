<?php
declare(strict_types=1);

namespace Desktop\Connections;

/** The colours a saved connection can take. Stored by name, not hex, so the palette can be
* retuned without losing anyone's choice; the hex is only what the page paints.
*
* A fixed set rather than a free colour picker: <input type="color"> cannot say "no colour",
* and every one of these has to read as a stripe on both schemes.
*/
enum Color: string {
	case Red = 'red';
	case Orange = 'orange';
	case Yellow = 'yellow';
	case Green = 'green';
	case Sky = 'sky';
	case Blue = 'blue';
	case Purple = 'purple';
	case Pink = 'pink';
	case Gray = 'gray';

	function hex(): string {
		return match ($this) {
			self::Red => '#f04438',
			self::Orange => '#f79009',
			self::Yellow => '#eaaa08',
			self::Green => '#12b76a',
			self::Sky => '#0ba5ec',
			self::Blue => '#2e90fa',
			self::Purple => '#7a5af8',
			self::Pink => '#ee46bc',
			self::Gray => '#667085',
		};
	}
}
