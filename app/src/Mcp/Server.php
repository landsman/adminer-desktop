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
* The agent reaches this over stdio through mcp.php, which replays the window's cookies —
* so by the time dispatch() runs, Adminer has connected as the logged-in user and Tools can
* simply ask. If the cookies were missing or stale, this code never runs at all: Adminer answers
* the login page instead, which is exactly the failure we want.
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
	private const string PROTOCOL = '2025-11-25';

	private Tools $tools;

	function __construct(?Tools $tools = null, bool $write = false) {
		$this->tools = $tools ?? new Tools($write);
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
				]),
				'tools/list' => $this->result($id, ['tools' => $this->catalogue()]),
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
	* @return list<array<string,mixed>>
	*/
	private function catalogue(): array {
		$table = ['type' => 'object', 'properties' => ['table' => ['type' => 'string', 'description' => 'Table name.']], 'required' => ['table']];
		return [
			[
				'name' => 'current_connection',
				'description' => 'Which database this Adminer Desktop window is connected to (driver, server, database). Call this first when you do not know what you are querying.',
				'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
			],
			[
				'name' => 'list_tables',
				'description' => 'List every table and view in the connected database, with its type.',
				'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
			],
			[
				'name' => 'describe_table',
				'description' => 'Columns of one table: name, type, nullability, default and whether it is part of the primary key. Use before writing a query against a table you have not seen.',
				'inputSchema' => $table,
			],
			[
				'name' => 'preview_table_data',
				'description' => 'First rows of a table, for seeing the shape of real data without writing SQL.',
				'inputSchema' => [
					'type' => 'object',
					'properties' => [
						'table' => ['type' => 'string', 'description' => 'Table name.'],
						'limit' => ['type' => 'integer', 'description' => 'Rows to return, default 20, capped at 200.'],
					],
					'required' => ['table'],
				],
			],
			[
				'name' => 'execute_query',
				'description' => 'Run a read-only SQL query and return its rows. Runs inside a transaction that is always rolled back, so anything that writes has no effect — do not use it to modify data. Results are capped at 200 rows.',
				'inputSchema' => [
					'type' => 'object',
					'properties' => [
						'sql' => ['type' => 'string', 'description' => 'A single SQL statement, in the dialect of the connected driver.'],
						'limit' => ['type' => 'integer', 'description' => 'Rows to return, capped at 200.'],
					],
					'required' => ['sql'],
				],
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

		$payload = match ($name) {
			'current_connection' => $this->tools->connection(),
			'list_tables' => $this->tools->listTables(),
			'describe_table' => $this->tools->describeTable($this->required($table, 'table')),
			'preview_table_data' => $this->tools->previewTable($this->required($table, 'table'), $limit ?? 20),
			'execute_query' => $this->tools->query(
				$this->required(is_string($args['sql'] ?? null) ? $args['sql'] : '', 'sql'),
				$limit ?? 200,
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
