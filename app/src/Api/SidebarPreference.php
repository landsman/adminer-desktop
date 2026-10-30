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
		// Not FILTER_VALIDATE_BOOL: with FILTER_NULL_ON_FAILURE it reads a missing field as false.
		$hidden = filter_input(INPUT_POST, 'hidden');
		if ($hidden !== '1' && $hidden !== '0') {
			return 400;
		}
		(new UserSettings())->set(SettingKey::SidebarHidden, $hidden === '1');
		return 204;
	}
}
