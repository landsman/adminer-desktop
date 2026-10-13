<?php
declare(strict_types=1);

namespace Desktop\Connections;

use Desktop\AppVersion;
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

	/** Every connection on the list: the ones adminer holds a password for, in its order — the
	* same loop as its navigation(), one entry per server login instead of one per database —
	* then the ones logged out of, which only we remember.
	*
	* A login adminer holds and we have not seen yet is remembered here as it is listed, so that
	* logging out of it later keeps it on the list.
	* @return list<Connection>
	*/
	function saved(): array {
		$stored = $this->stored();
		$return = [];
		foreach ((array) ($_SESSION["pwds"] ?? []) as $driver => $servers) {
			foreach ((array) $servers as $server => $users) {
				foreach ((array) $users as $username => $password) {
					if ($password === null) {
						continue; // adminer's mark for a login that failed or was logged out
					}
					$key = "$driver|$server|$username";
					if (!isset($stored[$key])) {
						$stored[$key] = [];
					}
					$databases = array_keys((array) ($_SESSION["db"][$driver][$server][$username] ?? []));
					$return[$key] = $this->with((string) $driver, (string) $server, (string) $username, array_values(array_filter(array_map('strval', $databases), fn(string $db) => $db !== '')));
				}
			}
		}
		if ($stored != $this->stored()) {
			$this->settings->set(SettingKey::Connections, $stored);
		}
		foreach (array_keys($stored) as $key) {
			if (!isset($return[$key]) && ($parts = self::parts($key))) {
				$return[$key] = $this->with(...[...$parts, [], false]);
			}
		}
		return array_values($return);
	}

	/** The connection this window is logged into, or null on the login page. */
	function current(): ?Connection {
		if (!isset($_GET["username"]) || !defined('Adminer\DRIVER') || !\Adminer\connection()) {
			return null;
		}
		return $this->with(\Adminer\DRIVER, \Adminer\SERVER, (string) $_GET["username"]);
	}

	/** The login page: the cards, or a line saying there are none yet, then adminer's own login
	* form inside the "New connection" modal. Printed from the loginForm() hook, which is inside
	* adminer's form, so the modal's fields still post with it.
	*/
	function loginPage(): void {
		$saved = $this->saved();
		// adminer's form, captured and split: the field table into the modal's body, its submit
		// row (Login, Permanent login) into the footer. Called on adminer's own class so that
		// loginFormField() still goes through every plugin; if a later adminer changes the
		// markup, all of it lands in the body and the form still works.
		ob_start();
		(new \Adminer\Adminer())->loginForm();
		$form = (string) ob_get_clean();
		$at = strpos($form, "<p><input type='submit'");
		Latte::engine()->render(__DIR__ . "/connections.latte", [
			"desktop" => $this->desktop,
			"connections" => $saved,
			"icons" => $this->icons(),
			"fields" => $at === false ? $form : substr($form, 0, $at),
			"submit" => $at === false ? '' : substr($form, $at),
			// Open the modal on load for a login that was just posted and failed, and for a
			// logged-out card, which lands here with its server and username filled in.
			"adding" => !empty($_POST["auth"]) || isset($_GET["username"]),
		]);
	}

	/** At the foot of the login page beside the gear: which adminer and which adminer-desktop
	* this is, each a link to what changed in it, opened in the browser (target=_blank, which the
	* launcher hands over). Ours rather than adminer's .version, part of the logo's heading. */
	function versions(): void {
		Latte::engine()->render(__DIR__ . "/versions.latte", [
			"adminer" => \Adminer\VERSION,
			"desktop" => AppVersion::current(),
		]);
	}

	/** @return array<string,string> name => inline SVG, for the card's buttons */
	private function icons(): array {
		$return = [];
		foreach (["edit", "logout", "forget"] as $name) {
			$return[$name] = (string) file_get_contents(__DIR__ . "/icons/$name.svg");
		}
		return $return;
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
		if (!isset($this->stored()[$key])) {
			return;
		}
		$label = array_filter([
			'name' => mb_substr(trim((string) ($_POST["name"] ?? "")), 0, self::NAME_MAX),
			'color' => Color::tryFrom((string) ($_POST["color"] ?? ""))->value ?? '',
			'env' => Environment::tryFrom((string) ($_POST["env"] ?? ""))->value ?? '',
		], fn(string $value) => $value !== '');
		$all = $this->stored();
		$all[$key] = $label; // everything cleared still leaves it remembered, just unlabelled
		$this->settings->set(SettingKey::Connections, $all);
	}

	/** Log out of a saved connection without forgetting it: adminer drops the password, from this
	* session and from its permanent-login cookie, and the card stays, asking for the password
	* next time it is opened. */
	function logout(string $key): void {
		$parts = self::parts($key);
		if (!$parts) {
			return;
		}
		[$driver, $server, $username] = $parts;
		\Adminer\restart_session();
		unset($_SESSION["pwds"][$driver][$server][$username], $_SESSION["db"][$driver][$server][$username]);
		// adminer_permanent is "id:password …", the id the base64 of driver-server-username-db
		// (auth.inc.php). Keep every entry but this login's, whichever database it was for.
		$kept = [];
		foreach (explode(" ", (string) ($_COOKIE["adminer_permanent"] ?? "")) as $entry) {
			$id = explode(":", $entry, 2)[0];
			if ($entry !== '' && array_slice(array_map('base64_decode', explode("-", $id)), 0, 3) !== $parts) {
				$kept[] = $entry;
			}
		}
		\Adminer\cookie("adminer_permanent", implode(" ", $kept));
	}

	/** Log out and take it off the list: its name, colour and environment go with it. */
	function forget(string $key): void {
		$this->logout($key);
		$all = $this->stored();
		unset($all[$key]);
		$this->settings->set(SettingKey::Connections, $all);
	}

	/** Driver, server and username out of a key, or null for one that is not ours. The server
	* sits in the middle, so it is what is left once the ends are taken off.
	* @return array{string,string,string}|null
	*/
	private static function parts(string $key): ?array {
		$first = strpos($key, '|');
		$last = strrpos($key, '|');
		if ($first === false || $first === $last) {
			return null;
		}
		return [substr($key, 0, $first), substr($key, $first + 1, $last - $first - 1), substr($key, $last + 1)];
	}

	/** @param list<string> $databases */
	private function with(string $driver, string $server, string $username, array $databases = [], bool $signedIn = true): Connection {
		$label = $this->stored()["$driver|$server|$username"] ?? [];
		return new Connection(
			$driver,
			$server,
			$username,
			$databases,
			(string) ($label['name'] ?? ''),
			Color::tryFrom((string) ($label['color'] ?? '')),
			Environment::tryFrom((string) ($label['env'] ?? '')),
			$signedIn,
		);
	}

	/** Every connection remembered, key => name/color/env (only what was set; empty for one
	* never labelled). Being here is what keeps a logged-out connection on the list.
	* @return array<string,array<string,string>>
	*/
	private function stored(): array {
		$stored = $this->settings->get(SettingKey::Connections, []);
		return is_array($stored) ? $stored : [];
	}
}
