<?php
declare(strict_types=1);

namespace Desktop;

/** The keys UserSettings can store — the whole set of persisted preferences in one list.
*
* An enum rather than free strings so a key is written once and reused (head() reads the same
* UserResized the api wrote), a typo is a type error, and what the app persists is visible at
* a glance. The backing values are the JSON keys on disk, so they must stay stable once
* shipped.
*/
enum SettingKey: string {
	// Every size the user changed themselves, in pixels: what => px, under the same names the
	// api takes (sidebar, edit_field — Api\ResizePreference). One key rather than one per
	// widget, because there is nothing to say about any of them individually and the next
	// resizable thing should not need a new key on disk.
	case UserResized = 'user_resized_px';
	case Appearance = 'appearance';
	case Density = 'density';
	case Scaling = 'scaling';
	// One per light/dark side; Mode::designKey() maps a side to its key.
	case DesignLight = 'design_light';
	case DesignDark = 'design_dark';
	// What the user answered about each plugin, name => on, and only where it differs from
	// what the release ships — see Settings\Plugins\PluginList.
	case Plugins = 'plugins';
	// Whether an agent may query the database you are logged into, over MCP — see Desktop\Mcp.
	// Off unless the user says otherwise, and deliberately not in DEFAULT_ON territory: turning
	// it on hands read access to whatever this window is connected to, so it is a decision
	// somebody makes, never one a release makes for them.
	case Mcp = 'mcp';
}
