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
			"write" => (bool) $this->settings->get(SettingKey::McpWrite, false),
			"os" => Os::current()->label(),
			"commands" => $this->commands(),
			"status" => $this->status($enabled),
			"lastUsed" => $this->lastUsed(time()),
			"target" => $this->target(),
		]);
	}

	/** Store the answer. Absent means the box was unticked — an unchecked checkbox posts
	* nothing, so there is no "off" value to look for.
	*/
	function apply(): void {
		$this->settings->set(SettingKey::Mcp, isset($_POST["mcp"]));
		$this->settings->set(SettingKey::McpWrite, isset($_POST["mcp_write"]));
	}

	/** The registration command per agent, ready to paste.
	*
	* More than one because the server is not Claude Code's: MCP is the point of the protocol,
	* and every agent that speaks it registers a stdio server the same way — a name and a command
	* to run — only with its own CLI in front. So the difference between them is one word, and
	* printing only `claude` made a general server look like a Claude one.
	*
	* Anything not listed still works: its config wants the same command line, written as JSON.
	* That is what mcp.register_manual says, rather than this trying to emit a config file per
	* agent in a format each of them changes on its own schedule.
	*
	* @return array<string,string> agent label => command
	*/
	function commands(): array {
		$command = $this->serverCommand();
		return [
			'Claude Code' => 'claude mcp add adminer-desktop -- ' . $command,
			'Codex' => 'codex mcp add adminer-desktop -- ' . $command,
			'Gemini CLI' => 'gemini mcp add adminer-desktop -- ' . $command,
		];
	}

	/** What any of them has to run: the executable, and the flag that makes it the bridge.
	*
	* The launcher passes its own path in; -mcp then resolves the bundled PHP and app/ itself,
	* so this is one path rather than the two the user would otherwise have to find. Served
	* without the launcher (`make serve`, a checkout) there is no executable to name, so fall
	* back to spelling out the interpreter and the script.
	*/
	function serverCommand(): string {
		$exe = Env::Exe->get();
		if (is_string($exe) && $exe !== "") {
			return '"' . $exe . '" -mcp';
		}
		$root = dirname(__DIR__, 2); // app/
		return '"' . dirname($root) . '/bin/frankenphp" php-cli "' . $root . '/mcp.php"';
	}

	/** Which connection an agent would reach, or null when there is no handshake to say.
	*
	* The agent follows the window: the handshake is rewritten on every connected request, so
	* browsing to another database repoints it. That is the intended behaviour — it is "the
	* database this window is logged into" — but it is invisible unless the panel says so, and
	* with several servers open in turn the answer is genuinely not obvious.
	*/
	function target(): ?string {
		$handshake = $this->handshake->read();
		$target = $handshake['target'] ?? '';
		return $target !== '' ? $target : null;
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
