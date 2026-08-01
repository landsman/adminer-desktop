<?php
declare(strict_types=1);

namespace Desktop\Api;

use Desktop\SettingKey;
use Desktop\UserSettings;

/** Persist a width the user dragged something to — so far the sidebar (issue #11).
*
* A table rather than one handler each, because the job is identical down to the clamp: only
* the key and its range differ. The page posts on release
* (src/Assets/javascript/sidebar-resize.js) and AdminerDesktop::head() reads it back and emits
* it before paint, so a cold start opens at what was dragged rather than flashing the default
* and jumping.
*/
class ResizePreference {
	/** what may be resized, and the pixel range each is clamped to — keep in step with the
	* clamps in the scripts that post here.
	* @var array<string,array{SettingKey,int,int}> */
	private const WIDTHS = [
		'sidebar' => [SettingKey::SidebarWidth, 180, 640],
	];

	/** @return int the HTTP status to answer with */
	static function handle(): int {
		$width = filter_input(INPUT_POST, 'width', FILTER_VALIDATE_INT);
		$what = (string) filter_input(INPUT_POST, 'what');
		if ($width === false || $width === null || !isset(self::WIDTHS[$what])) {
			return 400;
		}
		// Clamp to the same range the drag enforces, so a crafted post can't wedge the sidebar
		// off-screen or a field down to nothing.
		[$key, $min, $max] = self::WIDTHS[$what];
		(new UserSettings())->set($key, max($min, min($max, $width)));
		return 204;
	}
}
