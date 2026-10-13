<?php
declare(strict_types=1);

namespace Desktop\Connections;

/** One saved login — what adminer remembers (driver, server, username, the databases opened)
* plus what the user said about it: a name, a colour, an environment.
*
* The identity is the server login, not the database: switching databases inside a connection
* must not drop its colour, and adminer remembers one password per server and user anyway.
*/
final readonly class Connection {
	/** @param list<string> $databases the ones adminer remembers being opened, "" never among them */
	function __construct(
		public string $driver,
		public string $server,
		public string $username,
		public array $databases = [],
		public string $name = '',
		public string $customColor = '',
		public ?Environment $environment = null,
	) {
	}

	/** The key on disk and in the form, stable for as long as the login is. */
	function key(): string {
		return "$this->driver|$this->server|$this->username";
	}

	/** What adminer itself would call it, and the card's subtitle once it has a name. */
	function address(): string {
		return "$this->username@" . ($this->server !== '' ? $this->server : 'localhost');
	}

	function title(): string {
		return $this->name !== '' ? $this->name : $this->address();
	}

	/** The user's colour, else the environment's, else none. */
	function color(): string {
		return $this->customColor !== '' ? $this->customColor : ($this->environment?->color() ?? '');
	}

	/** "PostgreSQL", or "MariaDB" where adminer has learned that the MySQL driver talks to one. */
	function system(): string {
		return \Adminer\get_setting("vendor-$this->driver-$this->server") ?: \Adminer\get_driver($this->driver);
	}

	/** The logo as inline SVG — inline so it takes currentColor from the card. */
	function icon(): string {
		$file = $this->driver === 'server' && $this->system() === 'MariaDB' ? 'mariadb' : $this->driver;
		$path = __DIR__ . "/icons/" . preg_replace('~\W~', '', $file) . ".svg";
		return (string) file_get_contents(is_file($path) ? $path : __DIR__ . "/icons/database.svg");
	}

	/** Where a click goes: the server's database list, or straight into one database.
	*
	* Templates print it with |nocheck: adminer leaves the port's colon unescaped
	* (adminer.php?pgsql=127.0.0.1:5432&…), which Latte's URL check reads as a scheme and blanks.
	* It is adminer's own relative URL, built from the session, never typed into a page. */
	function url(string $database = ''): string {
		return \Adminer\auth_url($this->driver, $this->server, $this->username, $database);
	}
}
