<?php
declare(strict_types=1);
namespace Desktop;

/** The keys UserSettings can store — the whole set of persisted preferences in one list.
*
* An enum rather than free strings so a key is written once and reused (head() reads the same
* SidebarWidth the endpoint wrote), a typo is a type error, and what the app persists is
* visible at a glance. The backing values are the JSON keys on disk, so they must stay stable
* once shipped.
*/
enum SettingKey: string {
	case SidebarWidth = 'sidebar_width';
}
