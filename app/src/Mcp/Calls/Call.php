<?php
declare(strict_types=1);

namespace Desktop\Mcp\Calls;

/** One line of the day's mcp-YYYY-MM-DD.log: one message an agent sent, and what became of it. */
final readonly class Call {
	function __construct(
		/** Where it is: the file's date and the line, 1-based ("2026-08-03:12"), for its own link. */
		public string $id,
		/** UTC, as the bridge wrote it. */
		public string $time,
		public CallStatus $status,
		public string $method,
		public string $tool = '',
		public ?int $durationMs = null,
		/** The saved connection's key and name, and the database, it ran on — empty when it never reached the app. */
		public string $connection = '',
		public string $connectionName = '',
		public string $database = '',
		/** What the agent was told went wrong. */
		public string $error = '',
		/** @var array<mixed>|null the whole JSON-RPC request; null on an old line */
		public ?array $request = null,
		/** An old tab-separated line's detail: the SQL or the table, cut to 500 bytes. */
		public string $detail = '',
	) {
	}

	/** The connection and database together, as the grid's column shows them. */
	function place(): string {
		return implode(' / ', array_filter([$this->connectionName ?: $this->connection, $this->database], fn(string $s) => $s !== ''));
	}

	/** The SQL, when the call carried one. */
	function sql(): ?string {
		$sql = $this->arguments()['sql'] ?? null;
		return is_string($sql) ? $sql : null;
	}

	/** One line saying what was asked: the SQL or the table. */
	function summary(): string {
		$table = $this->arguments()['table'] ?? null;
		$text = $this->sql() ?? (is_string($table) ? $table : $this->detail);
		return trim((string) preg_replace('~\s+~', ' ', $text));
	}

	/** The request as the agent sent it, indented. */
	function pretty(): string {
		return $this->request !== null ? (string) json_encode($this->request, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : $this->detail;
	}

	/** @return array<mixed> */
	private function arguments(): array {
		$arguments = $this->request['params']['arguments'] ?? null;
		return is_array($arguments) ? $arguments : [];
	}
}
