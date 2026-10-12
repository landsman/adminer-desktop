<?php
declare(strict_types=1);

namespace Desktop\Mcp;

use Desktop\I18n\Strings;

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
	/** Whether a statement that writes is kept. Off unless the user said otherwise. */
	private bool $write;

	function __construct(bool $write = false) {
		$this->write = $write;
	}

	/** Rows returned before we stop and say there were more. */
	private const int MAX_ROWS = 200;

	/** Cells are truncated at this many characters by default — a BLOB column should not be able
	* to push the useful columns out of the answer. A caller that needs the whole value asks for
	* it with a max_cell_length of 0. */
	const int MAX_CELL = 2000;

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
	* @return array{columns:list<string>,rows:list<array<string,mixed>>,truncated:bool,truncated_cells:list<array{row:int,column:string,length:int}>}
	*/
	function previewTable(string $table, int $limit = 20, int $maxCell = self::MAX_CELL): array {
		$limit = max(1, min($limit, self::MAX_ROWS));
		// idf_escape is the driver's own identifier quoting — never string-concatenate a name
		// that came from outside.
		return $this->select('SELECT * FROM ' . \Adminer\idf_escape($table) . ' LIMIT ' . ($limit + 1), $limit, $maxCell);
	}

	/** Run a caller's SQL and return its rows. Rolled back, always.
	*
	* The answer says so. A write is not refused here, it is undone — and RETURNING makes an
	* INSERT produce a perfectly ordinary result set, so without this the caller gets
	* {"rows":[{"id":6}]} and concludes it wrote. It did not: the id came from a sequence, which
	* postgres does not roll back, and the row went with the transaction. An agent that reports
	* that as a successful write is worse than one that cannot write at all.
	*
	* @return array{columns:list<string>,rows:list<array<string,mixed>>,truncated:bool,truncated_cells:list<array{row:int,column:string,length:int}>,rolled_back:bool,note:string}
	*/
	function query(string $sql, int $limit = self::MAX_ROWS, int $maxCell = self::MAX_CELL): array {
		$result = $this->select($sql, max(1, min($limit, self::MAX_ROWS)), $maxCell);
		return $result + ($this->write
			? ['rolled_back' => false, 'note' => Strings::t('mcp.agent_note_committed')]
			: ['rolled_back' => true, 'note' => Strings::t('mcp.agent_note_rolled_back')]);
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
	*
	* `truncated` is about rows; `truncated_cells` names every value that was cut, because a cut
	* text column fails nowhere at all — the data is just gone.
	*
	* @return array{columns:list<string>,rows:list<array<string,mixed>>,truncated:bool,truncated_cells:list<array{row:int,column:string,length:int}>}
	*/
	private function select(string $sql, int $limit, int $maxCell): array {
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
					// JSON is never cut: half a document does not parse, so it would cost the
					// context and answer nothing. ponytail: a multi-megabyte jsonb comes back
					// whole too; a per-call byte budget is the upgrade if that ever floods one.
					if ($maxCell > 0 && $length > $maxCell && !json_validate($value)) {
						// Characters, not bytes: substr() could cut a UTF-8 sequence in half.
						$row[$column] = mb_substr($value, 0, $maxCell, 'UTF-8') . '… [truncated]';
						$cut[] = ['row' => count($rows), 'column' => (string) $column, 'length' => $length];
					}
				}
				$rows[] = $row;
			}
			$columns = $rows === [] ? [] : array_map('strval', array_keys($rows[0]));
			return ['columns' => $columns, 'rows' => $rows, 'truncated' => $truncated, 'truncated_cells' => $cut];
		} finally {
			// finally, not after the return: a query that throws must still not leave a
			// transaction open on the connection the user is browsing in.
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
