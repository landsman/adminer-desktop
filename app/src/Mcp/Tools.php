<?php
declare(strict_types=1);

namespace Desktop\Mcp;

/** What an agent may actually do to the database, and the limits it does it under.
*
* Every method here runs inside a request Adminer has already authenticated and connected, so
* there is no connecting, no credentials and no driver choice to make — whatever this window is
* logged into is what the agent sees.
*
* Two limits are the point of the class rather than details of it:
*
* Reads are wrapped in a transaction that is always rolled back, so a statement that turns out
* to write leaves nothing behind. That is the database refusing, not us guessing: the
* alternative — scanning the SQL for INSERT and friends — is a blocklist, and a blocklist that
* has to be right about CTEs, stored procedures and five dialects is one that is wrong.
* ponytail: rollback, not a read-only transaction. `BEGIN READ ONLY` says so up front but is
* spelled differently per driver, and DDL still auto-commits on MySQL either way. If writes
* ever need to be genuinely impossible rather than merely undone, the honest fix is connecting
* as a read-only role, not more SQL.
*
* And every result is capped. An agent that asks for a million rows should get a page and a
* note saying so, because the row it needed is nearly always in the first few and the rest is
* somebody's context window.
*/
class Tools {
	/** Rows returned before we stop and say there were more. */
	private const MAX_ROWS = 200;

	/** Cells are truncated at this many characters — a BLOB column should not be able to push
	* the useful columns out of the answer. */
	private const MAX_CELL = 2000;

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
	* @return array{columns:list<string>,rows:list<array<string,mixed>>,truncated:bool}
	*/
	function previewTable(string $table, int $limit = 20): array {
		$limit = max(1, min($limit, self::MAX_ROWS));
		// idf_escape is the driver's own identifier quoting — never string-concatenate a name
		// that came from outside.
		return $this->select('SELECT * FROM ' . \Adminer\idf_escape($table) . ' LIMIT ' . ($limit + 1), $limit);
	}

	/** Run a caller's SQL and return its rows. Rolled back, always.
	*
	* The answer says so. A write is not refused here, it is undone — and RETURNING makes an
	* INSERT produce a perfectly ordinary result set, so without this the caller gets
	* {"rows":[{"id":6}]} and concludes it wrote. It did not: the id came from a sequence, which
	* postgres does not roll back, and the row went with the transaction. An agent that reports
	* that as a successful write is worse than one that cannot write at all.
	*
	* @return array{columns:list<string>,rows:list<array<string,mixed>>,truncated:bool,rolled_back:bool,note:string}
	*/
	function query(string $sql, int $limit = self::MAX_ROWS): array {
		return $this->select($sql, max(1, min($limit, self::MAX_ROWS))) + [
			'rolled_back' => true,
			'note' => 'This ran inside a transaction that was rolled back. Nothing was written. '
				. 'Any id returned by RETURNING came from a sequence and does not identify a stored row.',
		];
	}

	/** What this window is connected to — the answer to "which database am I even looking at".
	* @return array<string,string>
	*/
	function connection(): array {
		return [
			'driver' => \Adminer\DRIVER,
			'server' => \Adminer\SERVER,
			'database' => \Adminer\DB,
		];
	}

	/** Run one statement inside a transaction and roll it back.
	* @return array{columns:list<string>,rows:list<array<string,mixed>>,truncated:bool}
	*/
	private function select(string $sql, int $limit): array {
		$connection = \Adminer\connection();
		// begin()/rollback() are Driver methods, not free functions like most of adminer's API —
		// each driver spells the statement itself (BEGIN, START TRANSACTION, …).
		$driver = \Adminer\driver();
		$began = (bool) $driver->begin();
		try {
			$result = $connection->query($sql);
			if ($result === false || $result === true) {
				// No result set: either it failed, or it was a statement that returns nothing.
				// Both are worth saying out loud rather than answering with zero rows.
				$error = (string) ($connection->error ?? '');
				throw new McpError($error !== '' ? $error : 'the statement returned no result set');
			}
			$rows = [];
			$truncated = false;
			while ($row = $result->fetch_assoc()) {
				if (count($rows) >= $limit) {
					$truncated = true;
					break;
				}
				$rows[] = array_map([$this, 'cell'], $row);
			}
			$columns = $rows === [] ? [] : array_map('strval', array_keys($rows[0]));
			return ['columns' => $columns, 'rows' => $rows, 'truncated' => $truncated];
		} finally {
			// finally, not after the return: a query that throws must still not leave a
			// transaction open on the connection the user is browsing in.
			if ($began) {
				$driver->rollback();
			}
		}
	}

	/** Keep one oversized value from crowding out the rest of the row. */
	private function cell(mixed $value): mixed {
		if (is_string($value) && strlen($value) > self::MAX_CELL) {
			return substr($value, 0, self::MAX_CELL) . '… [truncated]';
		}
		return $value;
	}
}
