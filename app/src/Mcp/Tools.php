<?php
declare(strict_types=1);

namespace Desktop\Mcp;

use Desktop\Connections\Connection;
use Desktop\Connections\Connections;
use Desktop\Env;
use Desktop\I18n\Strings;

/** What an agent may actually do to the database, and the limits it does it under.
*
* Every method here runs inside a request Adminer has already authenticated and connected, so
* there is no connecting, no credentials and no driver choice to make — the connection the request
* is on is what the agent sees: the window's, or the saved one the call named (Endpoint::elsewhere()).
*
* Two limits are the point of the class rather than details of it:
*
* Reads go through adminer's own guard for untrusted SQL where the driver has one — postgres:
* one statement, inside BEGIN READ ONLY — and are otherwise wrapped in a transaction that is
* always rolled back. Either way it is the database refusing, not us guessing: the alternative —
* scanning the SQL for INSERT and friends — is a blocklist, and a blocklist that has to be right
* about CTEs, stored procedures and five dialects is one that is wrong.
* Neither is the boundary. A superuser runs a program on the server inside BEGIN READ ONLY
* (COPY … TO PROGRAM), and DDL auto-commits on MySQL. What makes a write or a takeover
* impossible is the login: a role with SELECT and nothing else (features/shell/mcp.feature
* asserts that one refuses). So a login that can step out of the query is refused outright
* (refuseOverprivileged()), with no switch to allow it: the switch would be the docs again.
*
* And every result is capped. An agent that asks for a million rows should get a page and a
* note saying so, because the row it needed is nearly always in the first few and the rest is
* somebody's context window.
*/
class Tools {
	/** Whether a statement that writes is kept. Off unless the user said otherwise. */
	private bool $write;

	/** Whether every value comes back whole, as if each call asked for whole_values. */
	private bool $whole;

	/** The saved ones, for list_connections and the window's id; none without it. */
	private ?Connections $connections;

	function __construct(bool $write = false, bool $whole = false, ?Connections $connections = null) {
		$this->write = $write;
		$this->whole = $whole;
		$this->connections = $connections;
	}

	/** Rows returned before we stop and say there were more. */
	private const int MAX_ROWS = 200;

	/** How long one agent statement may run before the database itself stops it.
	*
	* Under the 50 s a call to another connection waits (Endpoint::forward()) and the bridge's 60 s
	* (launcher/mcpbridge), so the statement is gone by the time the agent is told the call gave
	* up. Without it a query ran on after that, a write included: the bridge hanging up does not
	* reach a statement PHP is already waiting on.
	*/
	public const int STATEMENT_SECONDS = 45;

	/** The drivers whose query() honours SqlDb::$untrusted (drivers/pgsql.inc.php). */
	private const array UNTRUSTED_DRIVERS = ['pgsql'];

	/** Cells are truncated at this many characters unless the caller asks for whole_values — a
	* BLOB column should not be able to push the useful columns out of the answer. */
	public const int MAX_CELL = 2000;

	/** The tables in the current database, with their type.
	* @return array<string,string> name => "table"|"view"|…
	*/
	function listTables(): array {
		$tables = \Adminer\tables_list();
		return is_array($tables) ? $tables : [];
	}

	/** A table's columns: name, type, nullability, default.
	* @return list<array<string,mixed>>
	*/
	function describeTable(string $table): array {
		$out = [];
		$fields = \Adminer\fields($table);
		if (!is_array($fields)) {
			return $out;
		}
		foreach ($fields as $name => $field) {
			$out[] = [
				'name' => (string) $name,
				'type' => $field['full_type'] ?? ($field['type'] ?? ''),
				'null' => (bool) ($field['null'] ?? false),
				'default' => $field['default'] ?? null,
				'primary' => (bool) ($field['primary'] ?? false),
			];
		}
		return $out;
	}

	/** The first rows of a table, without the caller having to write SQL for the common case.
	* @return array{columns:list<string>,rows:list<array<string,mixed>>,truncated:bool,truncated_cells:list<array{row:int,column:string,length:int}>,truncated_note?:string,binary_cells:list<array{row:int,column:string,length:int}>,affected_rows?:int}
	*/
	function previewTable(string $table, int $limit = 20, bool $whole = false): array {
		$limit = max(1, min($limit, self::MAX_ROWS));
		// table() and limit() are the driver's own quoting and row cap (TOP on MSSQL, rownum on
		// Oracle) — never string-concatenate a name that came from outside, nor a LIMIT.
		return $this->select('SELECT' . \Adminer\limit("*\nFROM " . \Adminer\table($table), '', $limit + 1, 0, "\n"), $limit, $whole);
	}

	/** Run a caller's SQL and return its rows. Rolled back, always.
	*
	* The answer says so. A write is not refused here, it is undone — and RETURNING makes an
	* INSERT produce a perfectly ordinary result set, so without this the caller gets
	* {"rows":[{"id":6}]} and concludes it wrote. It did not: the id came from a sequence, which
	* postgres does not roll back, and the row went with the transaction. An agent that reports
	* that as a successful write is worse than one that cannot write at all.
	*
	* @return array{columns:list<string>,rows:list<array<string,mixed>>,truncated:bool,truncated_cells:list<array{row:int,column:string,length:int}>,truncated_note?:string,binary_cells:list<array{row:int,column:string,length:int}>,affected_rows?:int,rolled_back:bool,note:string}
	*/
	function query(string $sql, int $limit = self::MAX_ROWS, bool $whole = false): array {
		$result = $this->select($sql, max(1, min($limit, self::MAX_ROWS)), $whole);
		return $result + ($this->write
			? ['rolled_back' => false, 'note' => Strings::t('mcp.agent_note_committed')]
			: ['rolled_back' => true, 'note' => Strings::t('mcp.agent_note_rolled_back')]);
	}

	/** How a driver that connects with no database chosen says which one it landed in. */
	private const array CURRENT_DATABASE = ['pgsql' => 'SELECT current_database()', 'mssql' => 'SELECT DB_NAME()'];

	/** What this window is connected to — the answer to "which database am I even looking at".
	*
	* The database the queries run in, not the one in the window's URL: with none chosen there,
	* postgres connects to `postgres` and MS SQL to the login's default, and an empty answer hid
	* that the agent was querying the wrong one.
	*
	* @return array<string,string>
	*/
	function connection(): array {
		$database = \Adminer\DB;
		if ($database === '' && isset(self::CURRENT_DATABASE[\Adminer\DRIVER])) {
			$database = (string) \Adminer\get_val(self::CURRENT_DATABASE[\Adminer\DRIVER]);
		}
		return [
			'id' => $this->connections?->current()?->key() ?? '',
			'driver' => \Adminer\DRIVER,
			'server' => \Adminer\SERVER,
			'database' => $database,
		] + (\Adminer\DB === '' ? ['note' => Strings::t('mcp.agent_note_no_database')] : []);
	}

	/** Where a call ran, for the _meta of its answer: the bridge copies it into the record of what
	* an agent did (launcher/mcpbridge record.go), and with a call free to name another connection
	* or database, the window's would be the wrong answer. Not connection(): that asks the server.
	*
	* @return array{connection:string,connection_name:string,database:string}
	*/
	function where(): array {
		$current = $this->connections?->current();
		return [
			'connection' => $current?->key() ?? '',
			'connection_name' => $current?->title() ?? '',
			'database' => defined('Adminer\DB') ? \Adminer\DB : '',
		];
	}

	/** Every saved connection, the way the start screen lists them, with the id the other tools
	* take as `connection`. Signed-out ones too, so the agent can say which one to sign in to
	* rather than that it does not exist. `databases` is the ones opened before; list_databases
	* asks the server.
	* @return list<array<string,mixed>>
	*/
	function listConnections(): array {
		return array_map(fn(Connection $c) => [
			'id' => $c->key(),
			'name' => $c->name,
			'system' => $c->system(),
			'server' => $c->server,
			'username' => $c->username,
			'environment' => $c->environment->value ?? '',
			'databases' => $c->databases,
			'signed_in' => $c->signedIn,
		], $this->connections?->saved() ?? []);
	}

	/** PostgreSQL, everything that reaches the server's files and programs, as one list:
	* a superuser; a member of the roles that grant it, through pg_has_role() so a role the login
	* can SET ROLE to counts too; EXECUTE on the file and large-object functions, which can be
	* granted one by one without the roles; and CREATEROLE before 16, which could grant itself any
	* of those roles (16 asks for ADMIN on them). A superuser has all of it, so it is named alone.
	*/
	private const string PGSQL_OVERPRIVILEGED = <<<'SQL'
		WITH su AS (SELECT rolname FROM pg_roles WHERE rolsuper AND pg_has_role(current_user, oid, 'MEMBER'))
		SELECT 'SUPERUSER ' || rolname FROM su
		UNION ALL SELECT rolname FROM pg_roles
			WHERE rolname IN ('pg_read_server_files', 'pg_write_server_files', 'pg_execute_server_program')
			AND pg_has_role(current_user, oid, 'MEMBER') AND NOT EXISTS (SELECT FROM su)
		UNION ALL SELECT 'EXECUTE ' || f FROM unnest(ARRAY['pg_read_file(text)', 'pg_read_binary_file(text)', 'pg_ls_dir(text)', 'lo_import(text)', 'lo_export(oid, text)']) f
			WHERE has_function_privilege(current_user, f, 'EXECUTE') AND NOT EXISTS (SELECT FROM su)
		UNION ALL SELECT 'CREATEROLE ' || rolname FROM pg_roles
			WHERE rolcreaterole AND pg_has_role(current_user, oid, 'MEMBER') AND NOT EXISTS (SELECT FROM su)
			AND current_setting('server_version_num')::int < 160000
		SQL;

	/** Refuse a login whose privileges reach past a read-only query — what the transaction cannot
	* contain, a superuser running a program on the server inside BEGIN READ ONLY.
	*
	* About the server, not the data, so the writes setting changes nothing here. A check that
	* could not be read refuses too: an empty answer from a failed query reads exactly like a clean
	* login. PostgreSQL and MySQL/MariaDB only; the other drivers are not checked.
	*
	* @throws McpError
	*/
	function refuseOverprivileged(): void {
		$found = match (\Adminer\DRIVER) {
			'pgsql' => self::rows(self::PGSQL_OVERPRIVILEGED),
			// ponytail: MySQL 8 leaves a granted role's privileges out of a bare SHOW GRANTS; add USING
			// the active roles if logins that hold FILE through a role turn up.
			'server' => self::overprivilegedGrants(self::rows('SHOW GRANTS') ?: throw new McpError(Strings::t('mcp.agent_privileges_unknown'))),
			default => [],
		};
		if ($found !== []) {
			throw new McpError(Strings::t('mcp.agent_overprivileged') . ': ' . implode(', ', $found), refused: true);
		}
	}

	/** The first column of every row, or an McpError where adminer's get_vals() would answer an
	* empty list for a query that failed.
	* @return list<string>
	* @throws McpError
	*/
	private static function rows(string $sql): array {
		$result = \Adminer\connection()->query($sql);
		if (!is_object($result)) {
			throw new McpError(Strings::t('mcp.agent_privileges_unknown') . ': ' . \Adminer\connection()->error);
		}
		$rows = [];
		while ($row = $result->fetch_row()) {
			$rows[] = (string) $row[0];
		}
		return $rows;
	}

	/** One part of a grant's target: a backquoted name, a bare one, or *. */
	private const string GRANT_PART = '(?:`(?:[^`]|``)*`|[^\s`.]+)';

	/** Which lines of MySQL's SHOW GRANTS reach past one database's data: any privilege on *.*
	* but USAGE — a global SELECT reads mysql.user and its password hashes as much as FILE reads
	* the server's files — and anything on the mysql schema, a wildcard that matches it included.
	* ALL on one database is about that database's data, and is left to the writes setting.
	*
	* A line that names a target this cannot read is reported whole rather than skipped.
	*
	* @param list<string> $grants
	* @return list<string>
	*/
	static function overprivilegedGrants(array $grants): array {
		$found = [];
		foreach ($grants as $grant) {
			// A granted role reads GRANT `role`@`%` TO …, with no ON: not a privilege in itself.
			if (!preg_match('~ ON ~i', $grant)) {
				continue;
			}
			$part = self::GRANT_PART;
			if (!preg_match("~^GRANT (.+?) ON (?:(?:TABLE|FUNCTION|PROCEDURE) )?($part)(?:\\.($part))? TO ~i", $grant, $match)) {
				$found[] = $grant;
				continue;
			}
			$schema = str_starts_with($match[2], '`') ? str_replace('``', '`', substr($match[2], 1, -1)) : $match[2];
			if (preg_match('~^PROXY\b~i', $match[1])) {
				// Lets the login act as another account, whoever that is.
				$found[] = "PROXY ON $match[2]";
			} elseif ($match[2] === '*' && ($match[3] ?? '') === '*') {
				// Column lists off, SELECT (a, b) is a SELECT; MySQL 8 lists dynamic privileges with no space.
				$privileges = array_map(fn($p) => trim((string) preg_replace('~\s*\(.*~', '', $p)), explode(',', strtoupper($match[1])));
				array_push($found, ...array_diff($privileges, ['USAGE']));
			} elseif (preg_match(self::schemaPattern($schema), 'mysql')) {
				$found[] = "$match[1] ON $match[2]" . (isset($match[3]) ? ".$match[3]" : '');
			}
		}
		return array_values(array_unique($found));
	}

	/** A grant's schema name as a regex: MySQL reads _ and % in it as LIKE wildcards, \_ and \% as
	* the characters. */
	private static function schemaPattern(string $schema): string {
		$pattern = preg_replace_callback('~\\\\[_%]|[_%]|[^_%\\\\]+|\\\\~', fn($m) => match ($m[0]) {
			'_' => '.',
			'%' => '.*',
			'\_', '\%' => preg_quote($m[0][1], '~'),
			default => preg_quote($m[0], '~'),
		}, $schema);
		return "~^$pattern$~i";
	}

	/** The databases on this connection's server that the login may open.
	* @return list<string>
	*/
	function listDatabases(): array {
		return array_values(array_map('strval', \Adminer\adminer()->databases(false)));
	}

	/** Run one statement inside a transaction and roll it back.
	*
	* `truncated` is about rows; `truncated_cells` names every value that was cut, because a cut
	* text column fails nowhere at all — the data is just gone. `binary_cells` names every value
	* sent as hex, with its length in bytes: one raw BLOB byte would fail json_encode() for the
	* whole answer.
	*
	* @return array{columns:list<string>,rows:list<array<string,mixed>>,truncated:bool,truncated_cells:list<array{row:int,column:string,length:int}>,truncated_note?:string,binary_cells:list<array{row:int,column:string,length:int}>,affected_rows?:int}
	*/
	private function select(string $sql, int $limit, bool $whole): array {
		$connection = \Adminer\connection();
		// begin()/rollback() are Driver methods, not free functions like most of adminer's API —
		// each driver spells the statement itself (BEGIN, START TRANSACTION, …).
		$driver = \Adminer\driver();
		// Adminer's guard for SQL it did not write, where the driver has one: on postgres a single
		// statement over the extended protocol inside BEGIN READ ONLY, so a smuggled `COMMIT; …`
		// is refused instead of run after our rollback stopped covering it. It opens and closes
		// that transaction itself, and ours must stay out of the way: nested, its READ ONLY is
		// only a warning and its COMMIT commits ours. Elsewhere it changes nothing and ours stays —
		// mysqli runs one statement per query() regardless.
		$guarded = !$this->write && in_array(\Adminer\DRIVER, self::UNTRUSTED_DRIVERS, true);
		$restore = $this->limitTime();
		\Adminer\SqlDb::$untrusted = $guarded;
		$began = !$guarded && (bool) $driver->begin();
		try {
			$result = $connection->query($sql);
			if ($result === true) {
				// It ran and returns no rows: an INSERT, UPDATE or DDL. That is an answer, not an
				// error. Reported as one, a committed write read as a failed one, and an agent that
				// retried it wrote twice. affected_rows says what it touched; query() says whether
				// that was kept.
				return ['columns' => [], 'rows' => [], 'truncated' => false, 'truncated_cells' => [], 'binary_cells' => [], 'affected_rows' => (int) $connection->affected_rows];
			}
			if ($result === false) {
				$error = (string) ($connection->error ?? '');
				if ($this->limit > 0 && $this->timedOut($error)) {
					// Said as ours, with what to do: the database's own words do not say there is a
					// limit, nor that the window has none.
					throw new McpError(str_replace('{n}', (string) $this->limit, Strings::t('mcp.agent_statement_too_long')) . " ($error)");
				}
				throw new McpError($error !== '' ? $error : Strings::t('mcp.agent_no_result'));
			}
			$rows = [];
			$truncated = false;
			$cut = [];
			$binary = [];
			while ($row = $result->fetch_assoc()) {
				if (count($rows) >= $limit) {
					$truncated = true;
					break;
				}
				foreach ($row as $column => $value) {
					// Adminer's own test for "show this as text" (select_value()), so a BINARY(16)
					// that happens to be valid UTF-8 goes out as hex too, not as control characters.
					if (is_string($value) && !\Adminer\is_utf8($value)) {
						$binary[] = ['row' => count($rows), 'column' => (string) $column, 'length' => strlen($value)];
						$row[$column] = $value = bin2hex($value);
					}
					$length = is_string($value) ? mb_strlen($value, 'UTF-8') : 0;
					if (!$whole && !$this->whole && $length > self::MAX_CELL) {
						// Characters, not bytes: substr() could cut a UTF-8 sequence in half.
						$row[$column] = mb_substr($value, 0, self::MAX_CELL, 'UTF-8') . '… [truncated]';
						$cut[] = ['row' => count($rows), 'column' => (string) $column, 'length' => $length];
					}
				}
				$rows[] = $row;
			}
			$columns = $rows === [] ? [] : array_map('strval', array_keys($rows[0]));
			return ['columns' => $columns, 'rows' => $rows, 'truncated' => $truncated, 'truncated_cells' => $cut]
					// Said in the answer, not only in the tool description: this is the moment the
					// agent needs to know the whole value is one argument away.
					+ ($cut === [] ? [] : ['truncated_note' => Strings::t('mcp.agent_note_cut')])
					+ ['binary_cells' => $binary];
		} finally {
			// finally, not after the return: a query that throws must still not leave a
			// transaction open on the connection the user is browsing in, nor the guard on.
			\Adminer\SqlDb::$untrusted = false;
			if ($began) {
				// commit() only when the user asked for it. Note the transaction wraps the
				// statement either way: with writes on this is an ordinary commit, with them
				// off it is what undoes anything the statement did.
				if ($this->write) {
					$driver->commit();
				} else {
					$driver->rollback();
				}
			}
			if ($restore !== null) {
				\Adminer\connection()->query($restore);
			}
		}
	}

	/** Have the database stop this request's statements after STATEMENT_SECONDS, unless it already
	* stops them sooner.
	*
	* Per session, not per transaction: on postgres the guard opens its own transaction per query,
	* where a SET LOCAL of ours would not reach. A stricter limit already on the session — a role
	* given `statement_timeout = '5s'` — is kept rather than loosened, and the old value goes back
	* afterwards (restoreTime()), so a pooler that hands this connection on hands it on as it was.
	* MySQL's max_execution_time covers SELECT only; MariaDB's max_statement_time covers every
	* statement. Other drivers have no such setting and run unlimited, as before.
	*
	* Not a boundary: the agent's own SQL can SET it back. It is there so a query nobody is waiting
	* for any more does not run on; what the agent may do is the login's to say.
	*
	* @return string|null the statement that puts the session back, or null when nothing changed
	*/
	private function limitTime(): ?string {
		$connection = \Adminer\connection();
		$ours = self::statementSeconds();
		[$read, $set, $scale] = match (true) {
			\Adminer\DRIVER === 'pgsql' => ["SELECT setting FROM pg_settings WHERE name = 'statement_timeout'", 'SET statement_timeout = ', 1000],
			\Adminer\DRIVER === 'server' && $connection->flavor === 'maria' => ['SELECT @@SESSION.max_statement_time', 'SET SESSION max_statement_time = ', 1],
			\Adminer\DRIVER === 'server' => ['SELECT @@SESSION.max_execution_time', 'SET SESSION max_execution_time = ', 1000],
			default => [null, null, 1],
		};
		if ($read === null) {
			$this->limit = 0;
			return null;
		}
		// 0 is "no limit" in all three; the unit is milliseconds, MariaDB's seconds.
		$current = (float) \Adminer\get_val($read);
		if ($current > 0 && $current <= $ours * $scale) {
			$this->limit = (int) ceil($current / $scale);
			return null;
		}
		$this->limit = $ours;
		$connection->query($set . ($ours * $scale));
		return $set . $current;
	}

	/** STATEMENT_SECONDS, or the e2e fixture's shorter one. */
	static function statementSeconds(): int {
		return (int) Env::McpStatementSeconds->get() ?: self::STATEMENT_SECONDS;
	}

	/** The limit limitTime() left in force, in seconds, for the message that names it. */
	private int $limit = 0;

	/** Whether a failed statement was stopped by that limit. By MySQL's and MariaDB's error codes
	* (3024, 1969), which do not depend on the server's language; postgres' text, which does, so
	* a server in another language falls through to its own words. */
	private function timedOut(string $error): bool {
		if (\Adminer\DRIVER === 'server') {
			return in_array((int) (\Adminer\connection()->errno ?? 0), [3024, 1969], true);
		}
		return \Adminer\DRIVER === 'pgsql' && str_contains($error, 'canceling statement due to statement timeout');
	}
}
