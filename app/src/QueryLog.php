<?php
declare(strict_types=1);

namespace Desktop;

use Dibi\Bridges\Tracy\Panel;
use Dibi\Connection;
use Dibi\Event;
use Tracy\Debugger;

/** Shows every query adminer sends in the Tracy bar, through dibi's own SQL panel.
*
* Adminer talks to mysqli, pgsql and PDO directly, so no ready-made panel sees its queries.
* The afterQuery hook (patches/adminer-after-query.patch) hands each one over, and it is
* replayed into dibi's panel as an Event. The dibi connection is only there because an
* Event needs one: lazy, so it never connects, and the panel names it in its heading.
*
* Built only by Debug::plugins() under -debug, and dibi is a dev dependency, so a release
* has neither.
*/
class QueryLog extends \Adminer\Plugin {
	private Panel $panel;
	private ?Connection $connection = null;

	function __construct() {
		// No EXPLAIN: it reruns the query through dibi's connection, which has no server.
		$this->panel = new Panel(explain: false);
		Debugger::getBar()->addPanel($this->panel);
	}

	/** @param mixed $result adminer's Result, or a bool */
	function afterQuery(string $query, float $time, $result): void {
		// Built on the first query, by when adminer has picked the driver and the server.
		$this->connection ??= new Connection([
			'driver' => 'adminer',
			'host' => defined('Adminer\SERVER') ? \Adminer\SERVER : '',
			'lazy' => true,
			'result' => ['formatDate' => null, 'formatDateTime' => null],
		], defined('Adminer\DRIVER') ? \Adminer\DRIVER : null);

		$event = new Event($this->connection, Event::QUERY, $query);
		$event->time = $time;
		$event->count = is_object($result) && isset($result->num_rows) ? (int) $result->num_rows : null;
		// ponytail: no source link — the first frame outside dibi is this file, every time.
		$event->source = null;
		$this->panel->logEvent($event);
	}
}
