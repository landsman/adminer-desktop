<?php
declare(strict_types=1);

namespace Desktop\Api;

use Desktop\SettingKey;
use Desktop\UserSettings;

/** Persist whether the sidebar is hidden, from the button by the traffic lights
* (src/Assets/javascript/window-chrome.js). AdminerDesktop::bodyClass() reads it back, so the
* next page opens the way the user left it.
*/
class SidebarPreference {
	/** @return int the HTTP status to answer with */
	static function handle(): int {
		$hidden = filter_input(INPUT_POST, 'hidden', FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
		if ($hidden === null) {
			return 400;
		}
		(new UserSettings())->set(SettingKey::SidebarHidden, $hidden);
		return 204;
	}
}
