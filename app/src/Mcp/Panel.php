<?php
declare(strict_types=1);

namespace Desktop\Mcp;

use Desktop\Env;
use Desktop\Latte;
use Desktop\Os;
use Desktop\SettingKey;
use Desktop\UserSettings;

/** The settings tab for letting an agent query the open database.
*
* The switch is the small part. The reason this is a panel rather than a line in a document is
* the command: registering an MCP server means naming the app's own executable, and where that
* is differs by platform and by how it was installed — a bundle on macOS, /usr/lib under the
* .deb, wherever it was unpacked otherwise. The app is the only thing that knows, so it prints
* the command for this machine and the user copies it.
*
* Also why it says what is missing rather than only what to do: "not running" and "not logged
* in" look identical from the agent's side, and this is the one place that can tell them apart.
*/
class Panel {
	private \AdminerDesktop $desktop;
	private UserSettings $settings;
	private Handshake $handshake;
	private Activity $activity;

	function __construct(\AdminerDesktop $desktop, UserSettings $settings, ?Handshake $handshake = null, ?Activity $activity = null) {
		$this->desktop = $desktop;
		$this->settings = $settings;
		$this->handshake = $handshake ?? new Handshake();
		$this->activity = $activity ?? new Activity();
	}

	function panel(): void {
		$enabled = (bool) $this->settings->get(SettingKey::Mcp, false);
		Latte::engine()->render(__DIR__ . "/mcp-panel.latte", [
			"desktop" => $this->desktop,
			"enabled" => $enabled,
			"os" => Os::current()->label(),
			"command" => $this->command(),
			"status" => $this->status($enabled),
			"lastUsed" => $this->lastUsed(time()),
		]);
	}

	/** Store the answer. Absent means the box was unticked — an unchecked checkbox posts
	* nothing, so there is no "off" value to look for.
	*/
	function apply(): void {
		$this->settings->set(SettingKey::Mcp, isset($_POST["mcp"]));
	}

	/** The registration command for this install, ready to paste.
	*
	* The launcher passes its own path in; -mcp then resolves the bundled PHP and app/ itself,
	* so this is one path rather than the two the user would otherwise have to find. Served
	* without the launcher (`make serve`, a checkout) there is no executable to name, so fall
	* back to spelling out the interpreter and the script.
	*/
	function command(): string {
		$exe = Env::Exe->get();
		if (is_string($exe) && $exe !== "") {
			return 'claude mcp add adminer -- "' . $exe . '" -mcp';
		}
		$root = dirname(__DIR__, 2); // app/
		return 'claude mcp add adminer -- "' . dirname($root) . '/bin/frankenphp" php-cli "' . $root . '/mcp.php"';
	}

	/** When an agent last asked for something, in words — or null if none ever has.
	*
	* Relative rather than a timestamp: the question is "was that just now, or last week", and
	* nobody reads a clock time to answer it.
	*/
	function lastUsed(int $now): ?string {
		$last = $this->activity->last();
		if ($last === null) {
			return null;
		}
		$ago = max(0, $now - $last['at']);
		$when = match (true) {
			$ago < 60 => $this->desktop->t('mcp.used_moments'),
			$ago < 3600 => $this->desktop->t('mcp.used_minutes'),
			$ago < 86400 => $this->desktop->t('mcp.used_hours'),
			default => $this->desktop->t('mcp.used_days'),
		};
		return $when;
	}

	/** What is stopping it working, in the order the user has to fix them.
	*
	* @return array{ready:bool,text:string}
	*/
	function status(bool $enabled): array {
		if (!$enabled) {
			return ["ready" => false, "text" => $this->desktop->t('mcp.status_off')];
		}
		// The handshake is written on every connected request and retracted when the feature is
		// off, so its presence is exactly "there is a session an agent could borrow".
		if ($this->handshake->read() === null) {
			return ["ready" => false, "text" => $this->desktop->t('mcp.status_waiting')];
		}
		return ["ready" => true, "text" => $this->desktop->t('mcp.status_ready')];
	}
}
