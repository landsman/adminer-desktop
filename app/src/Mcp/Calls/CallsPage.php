<?php
declare(strict_types=1);

namespace Desktop\Mcp\Calls;

use Desktop\Latte;

/** Every MCP call an agent made, in a grid, and one of them in a modal: ?mcp_calls=.
*
* A page in adminer's own frame, sidebar and theme included, which adminer has no hook for. So it
* is drawn from inside the headers() hook, the first one on every page, before adminer has printed
* anything: page_header(), our grid, page_footer(), exit. page_header() calls headers() again, and
* $drawing is what makes that inner call abstain.
*
* Filters and the detail are links, so the page has no script of its own: the detail is the same
* page with ?mcp_call=, its modal opened on load (data-open-dialog, modal.js).
*/
class CallsPage {
	/** Rows drawn at most: two weeks of a busy agent is thousands, and nobody reads past these. */
	private const int MAX_ROWS = 500;

	private static bool $drawing = false;

	function __construct(private \AdminerDesktop $desktop, private CallLog $log = new CallLog()) {
	}

	/** Whether the page is being drawn, so the headers() call inside page_header() abstains. */
	static function drawing(): bool {
		return self::$drawing;
	}

	/** Draw the page and end the request. */
	function serve(): never {
		self::$drawing = true;
		$status = CallStatus::tryFrom((string) ($_GET["mcp_status"] ?? ''));
		$connection = (string) ($_GET["mcp_connection"] ?? '');
		$all = $this->log->all();
		$calls = array_values(array_filter($all, fn(Call $c) => ($status === null || $c->status === $status) && ($connection === '' || $c->connection === $connection)));
		$open = isset($_GET["mcp_call"]) ? $this->log->find((string) $_GET["mcp_call"]) : null;
		$connections = [];
		foreach ($all as $call) {
			if ($call->connection !== '') {
				$connections[$call->connection] = $call->connectionName ?: $call->connection;
			}
		}
		asort($connections);

		\Adminer\page_header($this->desktop->t('mcp.calls_title'), "", null);
		Latte::engine()->render(__DIR__ . "/calls-page.latte", [
			"desktop" => $this->desktop,
			"calls" => array_slice($calls, 0, self::MAX_ROWS),
			"total" => count($calls),
			"statuses" => CallStatus::cases(),
			"status" => $status,
			"connections" => $connections,
			"connection" => $connection,
			"open" => $open,
			"url" => fn(array $params): string => self::url($_GET, ['mcp_calls' => ''] + $params),
		]);
		\Adminer\page_footer();
		exit;
	}

	/** A link to the page, on the connection the request is on: the driver and the login (the keys
	* up to username, the order adminer's ME writes them in), and the database and schema. Built
	* from $_GET rather than ME because the settings panel that links here is answered before
	* adminer defines ME.
	*
	* @param array<mixed> $get
	* @param array<string,string> $params
	*/
	static function url(array $get, array $params): string {
		$at = array_search('username', array_keys($get), true);
		$keep = ($at === false ? [] : array_slice($get, 0, $at + 1, true)) + array_intersect_key($get, ['db' => 1, 'ns' => 1]);
		return '?' . http_build_query($keep + array_filter($params, fn(string $v, string $k) => $v !== '' || $k === 'mcp_calls', ARRAY_FILTER_USE_BOTH));
	}
}
