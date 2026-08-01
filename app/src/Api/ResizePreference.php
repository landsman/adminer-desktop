<?php
declare(strict_types=1);

namespace Desktop\Api;

use Desktop\SettingKey;
use Desktop\UserSettings;

/** Persist a width the user dragged something to: the sidebar (issue #11), or the edit form's
* fields.
*
* One handler for both, because the job is identical down to the clamp — only the key and its
* range differ, and those are the table below. The page posts on release
* (src/Assets/javascript/sidebar-resize.js, edit-field-width.js) and AdminerDesktop::head()
* reads them back and emits them before paint, so a cold start opens at what was dragged
* rather than flashing the default and jumping.
*/
class ResizePreference {
	/** what may be resized, and the pixel range each is clamped to — keep in step with the
	* clamps in the two scripts that post here. The names are also the keys on disk.
	* @var array<string,array{int,int}> */
	private const WIDTHS = [
		'sidebar' => [180, 640],
		'edit_field' => [240, 2000],
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
		[$min, $max] = self::WIDTHS[$what];
		$settings = new UserSettings();
		// Read, set the one that moved, write back: they share a key, so the others have to
		// survive a save of this one.
		$sizes = $settings->get(SettingKey::UserResized, []);
		$sizes = is_array($sizes) ? $sizes : [];
		$sizes[$what] = max($min, min($max, $width));
		$settings->set(SettingKey::UserResized, $sizes);
		return 204;
	}
}
