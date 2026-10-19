<?php
declare(strict_types=1);

namespace Desktop\Mcp;

use Desktop\I18n\Strings;

/** The MCP endpoint: JSON-RPC in, JSON-RPC out, over the request Adminer already authenticated.
*
* Model Context Protocol is JSON-RPC 2.0 with three methods that matter — `initialize` to agree
* a version, `tools/list` to describe what is on offer, `tools/call` to run one. There is no
* library here because there is nothing to a library: the transport is the HTTP request we are
* already inside, and the framing is json_decode.
*
* The agent reaches this over stdio through mcp.php and the launcher's gate, which adds the
* window's session (launcher/mcpgate) — so by the time dispatch() runs, Adminer has connected as
* the logged-in user and Tools can simply ask. If the session was missing or stale, this code
* never runs at all: Adminer answers the login page instead, which is exactly the failure we want.
*/
class Server {
	/** The newest MCP revision we implement.
	*
	* MCP versions are dates, and 2025-11-25 is the last of the `initialize`-handshake ones —
	* which is the whole protocol as far as this server is concerned. From 2026-07-28 the
	* version instead rides every request in `_meta`, alongside a mandatory `server/discover`
	* RPC we do not answer; the spec keeps a documented backward-compatibility path to the
	* handshake for exactly this case, so a newer client still negotiates down and works.
	*
	* Naming a version we do not implement would be the actual bug: `tools/list` and
	* `tools/call` are all we serve, and they are unchanged across every handshake revision.
	*/
	public const string PROTOCOL = '2025-11-25';

	/** Sent with `initialize`, which clients put in the model's system prompt — said before the
	* first call, so an unavailable app reads as "ask the user" rather than as a missing server
	* the model then builds its own way around.
	*/
	public const string INSTRUCTIONS = 'The databases of the connections saved in Adminer Desktop: the one its window is on, or any other signed-in one you name (list_connections). These tools are the only'
		. ' sanctioned way to reach them. When a tool answers that the app is unavailable, tell the user what it said and wait:'
		. ' do not connect to the database another way, read its credentials, or write a bridge of your own.';

	private Tools $tools;

	/** Whether the user turned the cut off for good, so the descriptions do not promise one. */
	private bool $whole;

	/** Whether writes are kept, so execute_query's description does not promise a rollback. */
	private bool $write;

	function __construct(?Tools $tools = null, bool $write = false, bool $whole = false) {
		$this->tools = $tools ?? new Tools($write, $whole);
		$this->whole = $whole;
		$this->write = $write;
	}

	/** Handle one JSON-RPC message.
	*
	* @param string $body the raw request body
	* @return string|null the response to write back, or null for a notification (which by
	*                     JSON-RPC's rules is answered with silence, not an empty object)
	*/
	function dispatch(string $body): ?string {
		$request = json_decode($body, true);
		if (!is_array($request)) {
			return $this->error(null, -32700, Strings::t('mcp.agent_parse_error'));
		}
		$id = $request['id'] ?? null;
		$method = is_string($request['method'] ?? null) ? $request['method'] : '';
		$params = is_array($request['params'] ?? null) ? $request['params'] : [];

		// A notification has no id and takes no answer — `notifications/initialized` is the one
		// every client sends, and replying to it makes strict clients complain.
		if ($id === null && str_starts_with($method, 'notifications/')) {
			return null;
		}

		try {
			return match ($method) {
				'initialize' => $this->result($id, [
					'protocolVersion' => $this->negotiate($params),
					'capabilities' => ['tools' => new \stdClass()],
					'serverInfo' => ['name' => 'adminer-desktop', 'version' => \Adminer\VERSION],
					'instructions' => self::INSTRUCTIONS,
				]),
				'tools/list' => $this->result($id, ['tools' => self::catalogue($this->whole, $this->write)]),
				'tools/call' => $this->result($id, $this->call($params)),
				'ping' => $this->result($id, new \stdClass()),
				default => $this->error($id, -32601, Strings::t('mcp.agent_unknown_method') . ": $method"),
			};
		} catch (McpError $e) {
			// The agent's problem, not ours: hand it back as a tool result it can react to.
			return $this->result($id, [
				'content' => [['type' => 'text', 'text' => $e->getMessage()]],
				'isError' => true,
			]);
		} catch (\Throwable $e) {
			return $this->error($id, -32603, $e->getMessage());
		}
	}

	/** Answer `initialize` with the newest revision both sides speak.
	*
	* Versions are ISO dates, so "older" is a string comparison. A client asking for something
	* newer than us is told what we actually implement and negotiates down; one asking for
	* something older is met where it is, because nothing we serve changed in between. Claiming
	* the client's version back unconditionally would be the tempting bug — it reads as
	* agreeable and promises whatever that revision added.
	*
	* @param array<string,mixed> $params
	*/
	private function negotiate(array $params): string {
		$asked = is_string($params['protocolVersion'] ?? null) ? $params['protocolVersion'] : '';
		return ($asked !== '' && $asked < self::PROTOCOL) ? $asked : self::PROTOCOL;
	}

	/** What `tools/list` advertises. Descriptions are written for the model, not for us — they
	* are the only instructions it gets about when to reach for each one.
	*
	* Static so the bridge can advertise it with no app behind it: see Stdio::unavailable().
	* @return list<array<string,mixed>>
	*/
	static function catalogue(bool $whole = false, bool $write = false): array {
		// Every tool but list_connections runs where these say, and on the window's connection and
		// database without them — so an agent reaches what it needs without the user clicking there.
		$where = [
			'connection' => ['type' => 'string', 'description' => 'An id from list_connections. Default: the connection the Adminer Desktop window is on.'],
			'database' => ['type' => 'string', 'description' => 'A database on that connection, as list_databases names it. Default: the one the window has open, on its own connection.'],
		];
		$schema = fn(array $properties = [], array $required = []): array => ['type' => 'object', 'properties' => $properties + $where] + ($required !== [] ? ['required' => $required] : []);
		$table = ['table' => ['type' => 'string', 'description' => 'Table name.']];
		$rows = [
			'limit' => ['type' => 'integer', 'description' => 'Rows to return, default 20, capped at 200.'],
			'whole_values' => ['type' => 'boolean', 'description' => 'Return every value whole, with no cut.'],
		];
		// Said in the description, so the agent can choose whole_values before its first call
		// rather than learning about the cut from a result it then has to ask for again.
		$cells = $whole ? 'Every value is returned whole.' : 'To keep one large column from filling your context, values longer than ' . Tools::MAX_CELL
			. ' characters are cut to ' . Tools::MAX_CELL . ' and listed in truncated_cells (row is 0-based) with their full length;'
			. ' pass whole_values: true when you need them whole.';
		return [
			[
				'name' => 'list_connections',
				'description' => 'Every connection saved in Adminer Desktop: its id (pass it as connection to the other tools), name, system, server, login, environment (local, dev, stage, prod), the databases opened on it before, and whether it is signed in. Only a signed-in one can be queried; for another, ask the user to sign in to it in the window.',
				'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
			],
			[
				'name' => 'current_connection',
				'description' => 'Where a call lands (connection id, driver, server, database): the window\'s connection and database, or the ones you name. Call this first when you do not know what you are querying.',
				'inputSchema' => $schema(),
			],
			[
				'name' => 'list_databases',
				'description' => 'The databases on a connection\'s server that its login may open.',
				'inputSchema' => $schema(),
			],
			[
				'name' => 'list_tables',
				'description' => 'List every table and view in a database, with its type.',
				'inputSchema' => $schema(),
			],
			[
				'name' => 'describe_table',
				'description' => 'Columns of one table: name, type, nullability, default and whether it is part of the primary key. Use before writing a query against a table you have not seen.',
				'inputSchema' => $schema($table, ['table']),
			],
			[
				'name' => 'preview_table_data',
				'description' => 'First rows of a table, for seeing the shape of real data without writing SQL. ' . $cells,
				'inputSchema' => $schema($table + $rows, ['table']),
			],
			[
				'name' => 'execute_query',
				// Follows the setting: an agent trusts this sentence, and with writes on a promise of a
				// rollback is the one that leads it to run something it believes will be undone.
				'description' => ($write
					? 'Run a SQL statement and return its rows. Writes are allowed in Adminer Desktop\'s settings, so whatever the statement changes is committed — except on a connection whose environment is prod, where it is rolled back; rolled_back in the answer says which.'
					: 'Run a read-only SQL query and return its rows. Runs inside a transaction that is always rolled back, so anything that writes has no effect — do not use it to modify data.')
					. ' Results are capped at 200 rows. ' . $cells,
				'inputSchema' => $schema(
					['sql' => ['type' => 'string', 'description' => 'A single SQL statement, in the dialect of the connected driver.'], 'limit' => ['type' => 'integer', 'description' => 'Rows to return, capped at 200.']] + array_diff_key($rows, ['limit' => 0]),
					['sql'],
				),
			],
		];
	}

	/** Run one tool and shape its answer the way MCP wants it.
	* @param array<string,mixed> $params
	* @return array<string,mixed>
	*/
	private function call(array $params): array {
		$name = is_string($params['name'] ?? null) ? $params['name'] : '';
		$args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
		$table = is_string($args['table'] ?? null) ? $args['table'] : '';
		$limit = is_int($args['limit'] ?? null) ? $args['limit'] : null;
		$whole = ($args['whole_values'] ?? false) === true;

		$payload = match ($name) {
			'list_connections' => $this->tools->listConnections(),
			'current_connection' => $this->tools->connection(),
			'list_databases' => $this->tools->listDatabases(),
			'list_tables' => $this->tools->listTables(),
			'describe_table' => $this->tools->describeTable($this->required($table, 'table')),
			'preview_table_data' => $this->tools->previewTable($this->required($table, 'table'), $limit ?? 20, $whole),
			'execute_query' => $this->tools->query(
				$this->required(is_string($args['sql'] ?? null) ? $args['sql'] : '', 'sql'),
				$limit ?? 200,
				$whole,
			),
			default => throw new McpError(Strings::t('mcp.agent_unknown_tool') . ": $name"),
		};

		// JSON in a text block: every MCP client renders it, and the model reads structured data
		// better than a table drawn in spaces.
		return ['content' => [[
			'type' => 'text',
			'text' => (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
		]]];
	}

	private function required(string $value, string $name): string {
		if ($value === '') {
			throw new McpError(Strings::t('mcp.agent_missing_argument') . ": $name");
		}
		return $value;
	}

	/** @param array<string,mixed>|\stdClass $result */
	private function result(mixed $id, array|\stdClass $result): string {
		return (string) json_encode(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
	}

	private function error(mixed $id, int $code, string $message): string {
		return (string) json_encode(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]]);
	}
}
