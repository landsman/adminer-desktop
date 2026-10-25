<?php
declare(strict_types=1);

namespace Desktop\Debug;

use Dibi\Bridges\Tracy\Panel;
use Dibi\Connection;
use Dibi\Event;
use Tracy\Debugger;
use Tracy\IBarPanel;

/** Shows every query adminer sends in the Tracy bar, through dibi's own SQL panel.
*
* Adminer talks to mysqli, pgsql and PDO directly, so no ready-made panel sees its queries.
* The afterQuery hook (patches/adminer-after-query.patch) hands each one over, and it is
* replayed into dibi's panel as an Event.
*
* Each query is marked "user" — what the user asked for: a SQL command, a select with its
* filters, an edit — or "app", what adminer ran around it. dibi has no column for that, but it
* adds a Connection column once events come from more than one connection, so there are two:
* lazy, so neither ever connects, named by the source. The hooks that say a query was the
* user's fire before it runs (sqlCommandQuery) or after (selectQuery, messageQuery), so the
* queries are only turned into events when the bar renders, user ones first.
*
* Built only by Tracy::plugins() under -debug, and dibi is a dev dependency, so a release
* has neither.
*/
class QueryLog extends \Adminer\Plugin implements IBarPanel {
	private Panel $panel;
	/** @var list<array{string, float, ?int}> sql, seconds, rows */
	private array $queries = [];
	/** @var list<string> what the hooks reported as the user's; one entry can hold several queries */
	private array $user = [];

	function __construct() {
		// No EXPLAIN: it reruns the query through dibi's connection, which has no server.
		$this->panel = new Panel(explain: false);
		Debugger::getBar()->addPanel($this);
	}

	/** @param mixed $result adminer's Result, or a bool */
	function afterQuery(string $query, float $time, $result): void {
		$rows = is_object($result) && isset($result->num_rows) ? (int) $result->num_rows : null;
		$this->queries[] = [trim($query), $time, $rows];
	}

	// The three hooks only note the query and abstain (null), so adminer still prints it.

	function sqlCommandQuery(string $query): ?string {
		$this->user[] = $query;
		return null;
	}

	function selectQuery(string $query, float $start, bool $failed = false): ?string {
		$this->user[] = $query;
		return null;
	}

	function messageQuery(string $query, string $time, bool $failed = false): ?string {
		$this->user[] = $query;
		return null;
	}

	function getTab(): string {
		$this->flush();
		return $this->panel->getTab();
	}

	/** Tracy caps a panel at 700×500 until it is resized by hand, too small for a query list;
	* this one takes the window instead. A prefix match: redirect and ajax bars suffix the id. */
	function getPanel(): ?string {
		$panel = $this->panel->getPanel();
		return $panel === null ? null : '<style>
			#tracy-debug [id^="tracy-debug-panel-Desktop-Debug-QueryLog"]:not(.tracy-panel-resized) {
				max-width: calc(100vw - 20px);
				max-height: calc(100vh - 60px);
			}
		</style>' . $panel;
	}

	/** Turn the collected queries into dibi events, user ones first. */
	private function flush(): void {
		$host = defined('Adminer\SERVER') ? \Adminer\SERVER : '';
		$connections = [];
		foreach (['user', 'app'] as $source) {
			$connections[$source] = new Connection([
				'driver' => 'adminer',
				'host' => $host,
				'lazy' => true,
				'result' => ['formatDate' => null, 'formatDateTime' => null],
			], $source);
		}
		$events = ['user' => [], 'app' => []];
		foreach ($this->queries as [$sql, $time, $rows]) {
			$source = $this->isUser($sql) ? 'user' : 'app';
			$event = new Event($connections[$source], Event::QUERY, $sql);
			$event->time = $time;
			$event->count = $rows;
			// ponytail: no source link — the first frame outside dibi is this file, every time.
			$event->source = null;
			$events[$source][] = $event;
		}
		foreach (array_merge($events['user'], $events['app']) as $event) {
			$this->panel->logEvent($event);
		}
		$this->queries = [];
	}

	/** Contained, not equal: messageQuery gets several queries of one action joined together. */
	private function isUser(string $sql): bool {
		foreach ($this->user as $text) {
			if ($sql !== '' && str_contains($text, $sql)) {
				return true;
			}
		}
		return false;
	}
}
