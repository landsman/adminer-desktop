<?php
declare(strict_types=1);

namespace Desktop\Mcp;

use Desktop\Connections\Connection;
use Desktop\Connections\Connections;
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
* asserts that one refuses).
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
	* @return array{columns:list<string>,rows:list<array<string,mixed>>,truncated:bool,truncated_cells:list<array{row:int,column:string,length:int}>,truncated_note?:string}
	*/
	function previewTable(string $table, int $limit = 20, bool $whole = false): array {
		$limit = max(1, min($limit, self::MAX_ROWS));
		// idf_escape is the driver's own identifier quoting — never string-concatenate a name
		// that came from outside.
		return $this->select('SELECT * FROM ' . \Adminer\idf_escape($table) . ' LIMIT ' . ($limit + 1), $limit, $whole);
	}

	/** Run a caller's SQL and return its rows. Rolled back, always.
	*
	* The answer says so. A write is not refused here, it is undone — and RETURNING makes an
	* INSERT produce a perfectly ordinary result set, so without this the caller gets
	* {"rows":[{"id":6}]} and concludes it wrote. It did not: the id came from a sequence, which
	* postgres does not roll back, and the row went with the transaction. An agent that reports
	* that as a successful write is worse than one that cannot write at all.
	*
	* @return array{columns:list<string>,rows:list<array<string,mixed>>,truncated:bool,truncated_cells:list<array{row:int,column:string,length:int}>,truncated_note?:string,rolled_back:bool,note:string}
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

	/** The databases on this connection's server that the login may open.
	* @return list<string>
	*/
	function listDatabases(): array {
		return array_values(array_map('strval', \Adminer\adminer()->databases(false)));
	}

	/** Run one statement inside a transaction and roll it back.
	*
	* `truncated` is about rows; `truncated_cells` names every value that was cut, because a cut
	* text column fails nowhere at all — the data is just gone.
	*
	* @return array{columns:list<string>,rows:list<array<string,mixed>>,truncated:bool,truncated_cells:list<array{row:int,column:string,length:int}>,truncated_note?:string}
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
		\Adminer\SqlDb::$untrusted = $guarded;
		$began = !$guarded && (bool) $driver->begin();
		try {
			$result = $connection->query($sql);
			if ($result === false || $result === true) {
				// No result set: either it failed, or it was a statement that returns nothing.
				// Both are worth saying out loud rather than answering with zero rows.
				$error = (string) ($connection->error ?? '');
				throw new McpError($error !== '' ? $error : Strings::t('mcp.agent_no_result'));
			}
			$rows = [];
			$truncated = false;
			$cut = [];
			while ($row = $result->fetch_assoc()) {
				if (count($rows) >= $limit) {
					$truncated = true;
					break;
				}
				foreach ($row as $column => $value) {
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
					+ ($cut === [] ? [] : ['truncated_note' => Strings::t('mcp.agent_note_cut')]);
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
		}
	}
}
