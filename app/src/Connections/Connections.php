<?php
declare(strict_types=1);

namespace Desktop\Connections;

use Desktop\Latte;
use Desktop\SettingKey;
use Desktop\UserSettings;

/** The saved connections: adminer's permanent logins, given a name, a colour and an environment.
*
* Adminer stays the owner of the logins themselves — the passwords, encrypted in its
* adminer_permanent cookie with the key permanentLogin() keeps, are restored into
* $_SESSION["pwds"] before any plugin code runs. This reads that list the way adminer's own
* sidebar does, and keeps only the user's labels for it, in settings.json under
* SettingKey::Connections, keyed by Connection::key(). Nothing secret is written here.
*/
class Connections {
	private const int NAME_MAX = 60;

	private \AdminerDesktop $desktop;
	private UserSettings $settings;

	function __construct(\AdminerDesktop $desktop, UserSettings $settings) {
		$this->desktop = $desktop;
		$this->settings = $settings;
	}

	/** Every login adminer remembers, in the order it remembers them — the same loop as
	* adminer's navigation(), one entry per server login instead of one per database.
	* @return list<Connection>
	*/
	function saved(): array {
		$return = [];
		foreach ((array) ($_SESSION["pwds"] ?? []) as $driver => $servers) {
			foreach ((array) $servers as $server => $users) {
				foreach ((array) $users as $username => $password) {
					if ($password === null) {
						continue; // adminer's mark for a login that failed or was logged out
					}
					$databases = array_keys((array) ($_SESSION["db"][$driver][$server][$username] ?? []));
					$return[] = $this->with((string) $driver, (string) $server, (string) $username, array_values(array_filter(array_map('strval', $databases), fn(string $db) => $db !== '')));
				}
			}
		}
		return $return;
	}

	/** The connection this window is logged into, or null on the login page. */
	function current(): ?Connection {
		if (!isset($_GET["username"]) || !defined('Adminer\DRIVER') || !\Adminer\connection()) {
			return null;
		}
		return $this->with(\Adminer\DRIVER, \Adminer\SERVER, (string) $_GET["username"]);
	}

	/** The cards, printed inside adminer's login form ahead of its fields (the loginForm() hook).
	* Nothing when there is nothing saved: then the form is the whole page, as before. */
	function cards(): void {
		$saved = $this->saved();
		if (!$saved) {
			return;
		}
		Latte::engine()->render(__DIR__ . "/connections.latte", [
			"desktop" => $this->desktop,
			"connections" => $saved,
			"editIcon" => (string) file_get_contents(__DIR__ . "/icons/edit.svg"),
			// A login that was just posted and failed comes back here with its error: show the
			// form it was typed into rather than hiding it behind the button.
			"adding" => !empty($_POST["auth"]),
		]);
	}

	/** One edit dialog per card. Printed from navigation(), outside adminer's login form, because
	* a form cannot sit inside another and Enter in a name field must not log in. */
	function dialogs(): void {
		Latte::engine()->render(__DIR__ . "/connection-dialogs.latte", [
			"desktop" => $this->desktop,
			"connections" => $this->saved(),
			"colors" => Color::cases(),
			"environments" => Environment::cases(),
		]);
	}

	/** The badge at the top of the sidebar once logged in, so the colour follows you around. */
	function badge(): void {
		$current = $this->current();
		if ($current && ($current->name !== '' || $current->environment || $current->color() !== '')) {
			Latte::engine()->render(__DIR__ . "/connection-badge.latte", ["connection" => $current]);
		}
	}

	/** Store what the edit dialog posted. Whitelisted throughout: the colour ends up in a style
	* attribute and the environment in a class name, so neither is ever stored as typed. */
	function apply(): void {
		$key = (string) ($_POST["desktop_connection"] ?? "");
		$known = array_map(fn(Connection $connection) => $connection->key(), $this->saved());
		if (!in_array($key, $known, true)) {
			return;
		}
		$label = array_filter([
			'name' => mb_substr(trim((string) ($_POST["name"] ?? "")), 0, self::NAME_MAX),
			'color' => Color::tryFrom((string) ($_POST["color"] ?? ""))->value ?? '',
			'env' => Environment::tryFrom((string) ($_POST["env"] ?? ""))->value ?? '',
		], fn(string $value) => $value !== '');
		$all = $this->labels();
		if ($label) {
			$all[$key] = $label;
		} else {
			unset($all[$key]); // everything cleared: forget it rather than store an empty row
		}
		$this->settings->set(SettingKey::Connections, $all);
	}

	/** @param list<string> $databases */
	private function with(string $driver, string $server, string $username, array $databases = []): Connection {
		$label = $this->labels()["$driver|$server|$username"] ?? [];
		return new Connection(
			$driver,
			$server,
			$username,
			$databases,
			(string) ($label['name'] ?? ''),
			Color::tryFrom((string) ($label['color'] ?? '')),
			Environment::tryFrom((string) ($label['env'] ?? '')),
		);
	}

	/** @return array<string,array<string,string>> key => name/color/env, only what was set */
	private function labels(): array {
		$stored = $this->settings->get(SettingKey::Connections, []);
		return is_array($stored) ? $stored : [];
	}
}
