<?php
declare(strict_types=1);

namespace Desktop\Tests;

require_once dirname(__DIR__) . '/harness/fixture.php';

use Behat\Behat\Context\Context;
use Behat\Behat\Hook\Scope\AfterStepScope;
use Behat\Hook\AfterScenario;
use Behat\Hook\AfterStep;
use Behat\Hook\BeforeScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use Playwright\Page\PageInterface;
use RuntimeException;

/** Every step the browser features are written in.
 *
 * One instance per scenario, so $page is a browser nobody else has touched. The database and the
 * app server are not: they are booted once per driver and kept in $fixtures, which is by far the
 * slowest part of a run and has nothing a scenario can spoil.
 *
 * Which driver an instance is for comes from the Behat suite it belongs to, see behat.php.
 *
 * One file, deliberately. The steps are named for what the user is doing and are used across
 * features — splitting them by subject would mean either several contexts that cannot share the
 * page, or traits that no static analyser can follow.
 *
 * Everything read out of the page is a number, a boolean or a short string: handing this
 * playwright binding a multi-kilobyte innerHTML gets null back instead, which reads exactly like a
 * missing element.
 */
class DesktopContext implements Context
{

	/** @var array<string, mixed> */
	private array $fix;

	private PageInterface $page;

	private string $driver;

	/** @var array<string, mixed> what the last measured action started from */
	private array $before = [];

	/** @var array<string, mixed> and what it ended at */
	private array $after = [];

	/** @var ?string the column the last drag grabbed, so the assertions need not name it twice */
	private ?string $dragged = null;

	/** @var array<string, string> what the last login typed, which a failed one gives back */
	private array $typed = [];

	private bool $hidSidebar = false;

	public function __construct(string $driver)
	{
		$this->driver = $driver;
	}

	#[BeforeScenario]
	public function open(): void
	{
		putenv("ADMINER_DESKTOP_E2E_DRIVER=$this->driver"); // read by e2e_driver()
		$this->fix = e2e_fixture();
		$this->page = $this->newPage();
	}

	#[AfterScenario]
	public function close(): void
	{
		$errors = [];
		try {
			$errors = (array) $this->page->evaluate(
				// language=JavaScript
				'() => { try { return JSON.parse(sessionStorage.getItem("ad-e2e-errors") || "[]"); } catch { return []; } }',
			);
		} catch (\Throwable) {
			// A page that is not ours (about:blank, a closed one) has nothing recorded to read.
		}
		$this->page->context()->close();
		// The scenario hid the sidebar, which the app stores; every later one expects it shown.
		if ($this->hidSidebar) {
			$file = $this->fix['data'] . '/settings.json';
			$stored = e2e_settings($this->fix);
			unset($stored['sidebar_hidden']);
			file_put_contents($file, (string) json_encode($stored));
			$this->hidSidebar = false;
		}
		// Ours fail the scenario; adminer's own (adminer.php?file=…) are upstream's to fix, and are
		// printed so they are seen.
		$ours = array_filter($errors, fn($error) => str_contains((string) $error, '/src/'));
		if ($theirs = array_diff($errors, $ours)) {
			fwrite(STDERR, "a script of adminer's failed on the page (not failed):\n" . implode("\n", $theirs) . "\n");
		}
		if ($ours) {
			throw new RuntimeException("a script failed on the page:\n" . implode("\n", $ours));
		}
	}

	/** Save the page a step failed on, so a failure in CI is more than a line of text.
	 *
	 * isset() because booting the fixture can fail before there is a page, and a second error
	 * thrown from here would be the only one reported.
	 */
	#[AfterStep]
	public function report(AfterStepScope $scope): void
	{
		if (!$scope->getTestResult()->isPassed() && isset($this->page)) {
			$name = basename($scope->getFeature()->getFile(), '.feature') . '-' . $scope->getStep()->getLine();
			e2e_report($this->page, $this->fix, $name);
		}
	}

	// ── arranging ────────────────────────────────────────────────────────────────────────────

	#[Given('the settings are at their defaults')]
	public function settingsAreDefault(): void
	{
		@unlink($this->fix['data'] . '/settings.json');
	}

	/** Straight to settings.json rather than through the dialog: check.sh already proves the POST
	 * path, and a plugin feature is about what the plugin does once it is on. One at a time,
	 * because several hook the same thing and the first non-empty return wins.
	 */
	#[Given('only the :plugin plugin is on')]
	public function onlyPluginIsOn(string $plugin): void
	{
		file_put_contents(
			$this->fix['data'] . '/settings.json',
			(string) json_encode(['plugins' => [$plugin => true]]),
		);
	}

	#[Given('the text is scaled to :percent percent')]
	public function textIsScaled(string $percent): void
	{
		file_put_contents(
			$this->fix['data'] . '/settings.json',
			(string) json_encode(['scaling' => $percent] + e2e_settings($this->fix)),
		);
	}

	/** What the panel is laid out at, against the body's text, which carries the scale. */
	#[Then('the sidebar is :ems times the text size wide')]
	public function sidebarIsEms(string $ems): void
	{
		$at = (array) $this->page->evaluate(
			// language=JavaScript
			"() => ({
				width: parseFloat(getComputedStyle(document.querySelector('#foot')).flexBasis),
				text: parseFloat(getComputedStyle(document.body).fontSize),
			})",
		);
		if (abs($at['width'] - (float) $ems * $at['text']) > 1) {
			throw new RuntimeException("the sidebar is not $ems times the text: " . json_encode($at));
		}
	}

	/** The scheme the OS is pretending to be is a browser-context option, so this opens a new one —
	 * which is why it comes before logging in rather than after.
	 */
	#[Given('the browser is in the :scheme scheme')]
	public function browserIsInScheme(string $scheme): void
	{
		$this->page->context()->close();
		$this->page = $this->newPage(['colorScheme' => $scheme]);
	}

	/** Past the offer to name the connection too, which a first login opens over the page: closed,
	 * the way a user who has no name for it yet would, so it covers nothing the scenario clicks.
	 */
	#[Given('I am logged in')]
	public function logIn(): void
	{
		e2e_login($this->page, $this->fix);
		$this->page->evaluate(
			// language=JavaScript
			'() => document.querySelector("#ad-connection-added[open] [data-close]")?.click()',
		);
	}

	// ── going places ─────────────────────────────────────────────────────────────────────────

	#[When('I open the :table table')]
	public function openTable(string $table): void
	{
		$this->go(['select' => $table]);
	}

	#[When('I open the :table table :limit rows to a page')]
	public function openTableWithLimit(string $table, string $limit): void
	{
		$this->go(['select' => $table, 'limit' => $limit]);
	}

	/** No db, so adminer lists the server's databases; null drops it from the query. */
	#[When('I open the server\'s list of databases')]
	public function openDatabaseList(): void
	{
		$this->go(['db' => null, 'ns' => null]);
	}

	#[When('I open the database')]
	public function openDatabase(): void
	{
		$this->go([]);
	}

	#[When('I open the structure of the :table table')]
	public function openStructure(string $table): void
	{
		$this->go(['table' => $table]);
	}

	#[When('I open the edit form for :table row :id')]
	public function openEditForm(string $table, string $id): void
	{
		$this->go(['edit' => $table, 'where[id]' => $id]);
	}

	#[When('the window is :width pixels wide')]
	public function windowIsWide(string $width): void
	{
		$this->page->setViewportSize((int) $width, 900);
	}

	#[When('I open the list of tables')]
	public function openTableList(): void
	{
		$this->go([]);
	}

	/** Nothing in the panel wider than the panel, a table's own scroller included — and the comment
	 * the seed gives orders is on the page, or this would pass on one without it. */
	#[Then('the page fits inside the content panel')]
	public function pageFitsPanel(): void
	{
		/** @var array{comment: bool, over: list<string>} $state */
		$state = $this->page->evaluate(
			// language=JavaScript
			'() => ({
				comment: document.querySelector("#content").textContent.includes("Orders placed by users."),
				over: [document.querySelector("#content"), ...document.querySelectorAll("#content .scrollable")]
					.filter((e) => e.scrollWidth > e.clientWidth + 1)
					.map((e) => e.id || e.className),
			})',
		);
		if (!$state['comment']) {
			throw new RuntimeException("the seeded comment on orders is not on the page");
		}
		if ($state['over'] !== []) {
			throw new RuntimeException('scrolls sideways: ' . implode(', ', $state['over']));
		}
	}

	/** Every field's right edge inside the content panel's, the JSON editor's <pre> included —
	 * a field past it is cut off by the panel's own scrollport. */
	#[Then('every field fits inside the content panel')]
	public function fieldsFitPanel(): void
	{
		$over = (int) $this->page->evaluate(
			// language=JavaScript
			'() => {
				const edge = document.querySelector("#content").getBoundingClientRect().right;
				return [...document.querySelectorAll("#form > table.layout :is(textarea, pre.jush)")]
					.filter((f) => f.offsetParent !== null && f.getBoundingClientRect().right > edge + 1).length;
			}',
		);
		if ($over > 0) {
			throw new RuntimeException("$over fields run past the content panel");
		}
	}

	/** Delete sits beside Save, so it must not wear Save's fill: forms.css paints adminer's
	 * destructive submits, which it marks only by name, in the danger colour. */
	#[Then('Delete is not painted like Save')]
	public function deleteUnlikeSave(): void
	{
		/** @var array{save: ?string, delete: ?string} $bg */
		$bg = $this->page->evaluate(
			// language=JavaScript
			'() => {
				const bg = (el) => el ? getComputedStyle(el).backgroundColor : null;
				return {
					save: bg(document.querySelector("#form input[type=submit]:not([name])")),
					delete: bg(document.querySelector("#form input[type=submit][name=delete]")),
				};
			}',
		);
		if ($bg['save'] === null || $bg['delete'] === null) {
			throw new RuntimeException('the edit form has no Save or no Delete: ' . json_encode($bg));
		}
		if ($bg['save'] === $bg['delete']) {
			throw new RuntimeException("Delete shares Save's background, {$bg['save']}");
		}
	}

	/** The import page is the sql page in import mode: `import=` is what flips it (adminer.php sets
	 * $_GET["sql"] from it), and that is the page carrying the sql_file[] upload.
	 */
	#[When('I open the import page')]
	public function openImportPage(): void
	{
		$this->go(['import' => '']);
	}

	/** Adminer puts `sql=` into the command's textarea. */
	#[When('I open the SQL command with :query')]
	public function openSqlCommand(string $query): void
	{
		$this->go(['sql' => $query]);
	}

	#[When('I open the export')]
	public function openExport(): void
	{
		$this->go(['dump' => '']);
	}

	#[When('I pick :output as the export\'s output')]
	public function pickExportOutput(string $output): void
	{
		$this->page->evaluate(
			// language=JavaScript
			'(output) => document.querySelector(`#content [name=output][value="${output}"]`).click()',
			$output,
		);
	}

	#[When('I reload the page')]
	public function reload(): void
	{
		$this->goto($this->page->url());
	}

	/** A reload as Cmd+R makes it: the navigation's type is "reload", which "I reload the page"
	 * (a fresh navigation to the same url) is not. */
	#[When('I reload the window')]
	public function reloadWindow(): void
	{
		$this->page->reload();
		$this->page->waitForLoadState('networkidle');
	}

	// ── the data list ────────────────────────────────────────────────────────────────────────

	/** Adminer's heading link, addressed by the column it sorts rather than by its position: the
	 * span beside it holds the search and descending links, and the count differs per driver.
	 */
	#[When('I sort by the :column column')]
	public function sortBy(string $column): void
	{
		$this->before = $this->list();
		$this->page->evaluate(
			// language=JavaScript
			"() => document.querySelector('[id=\"th[$column]\"] a').click()",
		);
		$this->after = $this->settled($this->before, fn (): array => $this->list());
	}

	#[When('I step to the next page')]
	public function stepToNextPage(): void
	{
		$this->before = $this->list() + $this->pager();
		// The first control that is a link: on page one, first and previous are spans.
		$this->page->evaluate(
			// language=JavaScript
			"() => [...document.querySelectorAll('a.ad-page-step')][0].click()",
		);
		$this->after = $this->settled($this->before, fn (): array => $this->list() + $this->pager());
	}

	#[When('I pick page :number from the list')]
	public function pickPage(string $number): void
	{
		$this->before = $this->list() + $this->pager();
		// By value, not by label: the option labelled 10 is page 9, and a bare string would match
		// the label. Adminer numbers pages from zero.
		$value = (int) $number - 1;
		$this->page->evaluate(
			// language=JavaScript
			"() => {
				const list = document.querySelector('.ad-page-select');
				list.value = '$value';
				list.dispatchEvent(new Event('change'));
			}",
		);
		$this->after = $this->settled($this->before, fn (): array => $this->list() + $this->pager());
	}

	#[When('I pick :limit rows a page')]
	public function pickPageSize(string $limit): void
	{
		$this->page->locator(
			// language=JQuery-CSS
			"#form select[name='limit']",
		)->selectOption($limit);
		$this->page->waitForURL("**limit=$limit**");
		$this->page->waitForLoadState('networkidle');
	}

	#[Then('the rows came back in a different order')]
	public function rowsReordered(): void
	{
		if ($this->after['first'] === $this->before['first']) {
			throw new RuntimeException("the rows did not move, '{$this->after['first']}' is still first");
		}
	}

	#[Then('the rows moved')]
	public function rowsMoved(): void
	{
		$this->rowsReordered();
	}

	#[Then('the row count is unchanged')]
	public function rowCountUnchanged(): void
	{
		if ($this->after['rows'] !== $this->before['rows']) {
			throw new RuntimeException("the swap left {$this->after['rows']} rows, was {$this->before['rows']}");
		}
	}

	/** Only a new document loses the marker, which is the thing paging and sorting exist not to do. */
	#[Then('the document was not rebuilt')]
	public function documentNotRebuilt(): void
	{
		if ($this->after['sameDocument'] !== true) {
			throw new RuntimeException('the page was rebuilt instead of the rows being swapped');
		}
	}

	/** Adminer colours the values once at load, so anything swapped in afterwards is plain text
	 * unless it is asked for again.
	 */
	#[Then('the values are still highlighted')]
	public function valuesStillHighlighted(): void
	{
		if ($this->after['highlighted'] < $this->before['highlighted']) {
			throw new RuntimeException(
				"the rows that arrived have {$this->after['highlighted']} highlighted spans, was {$this->before['highlighted']}",
			);
		}
	}

	#[Then('the URL contains :text')]
	public function urlContains(string $text): void
	{
		if (!str_contains($this->page->url(), $text)) {
			throw new RuntimeException('the URL is ' . $this->page->url());
		}
	}

	#[Then('the URL says page :number')]
	public function urlSaysPage(string $number): void
	{
		$at = $this->pager();
		if ($at['page'] !== $number || $at['at'] !== $number) {
			throw new RuntimeException("the url says page={$at['page']} and the list says {$at['at']}");
		}
	}

	#[Then('the first row is unchanged')]
	public function firstRowUnchanged(): void
	{
		$now = $this->list();
		if ($now['first'] !== $this->after['first']) {
			throw new RuntimeException("the first row is '{$now['first']}', not the '{$this->after['first']}' it was");
		}
	}

	#[Then('/^(\d+) rows are listed$/')]
	public function rowsAreListed(int $count): void
	{
		$now = $this->list();
		if ($now['rows'] !== $count) {
			throw new RuntimeException("{$now['rows']} rows are listed, not $count");
		}
	}

	// ── the pager ────────────────────────────────────────────────────────────────────────────

	#[Then('the pager offers first, previous, next and last')]
	public function pagerOffersFourSteps(): void
	{
		$pager = $this->pager();
		if ($pager['steps'] !== 4) {
			throw new RuntimeException("the pager has {$pager['steps']} step controls, not four");
		}
	}

	#[Then('the page list offers :count pages')]
	public function pageListOffers(int $count): void
	{
		$pager = $this->pager();
		if ($pager['pages'] !== $count) {
			throw new RuntimeException("the page list offers {$pager['pages']} pages, not $count");
		}
	}

	/** The word comes from the page's #ad-i18n block; without it the script falls back to "/". */
	#[Then('the count beside it reads :count')]
	public function countReads(string $count): void
	{
		$pager = $this->pager();
		if (!str_contains((string) $pager['total'], $count)) {
			throw new RuntimeException("the count reads '{$pager['total']}', not '$count'");
		}
	}

	#[Then('the chip reads :range')]
	public function chipReads(string $range): void
	{
		$pager = $this->pager();
		if ($pager['range'] !== $range) {
			throw new RuntimeException("the chip reads '{$pager['range']}', not '$range'");
		}
	}

	/** Every mark is an icon file, masked so it takes the row's colour. A path that stopped
	 * resolving would leave the buttons blank and everything else here still passing.
	 */
	#[Then('every step control is drawn from an icon file')]
	public function stepsAreDrawn(): void
	{
		$pager = $this->pager();
		if ($pager['drawn'] !== 4 || $pager['chevron'] !== true) {
			throw new RuntimeException(
				"{$pager['drawn']} of 4 marks are drawn from icons/, chevron: " . ($pager['chevron'] ? 'yes' : 'no'),
			);
		}
	}

	/** A step with nowhere to go is a <span>, so it neither invites a click nor moves the rows. */
	#[Then('first and previous lead nowhere')]
	public function endsLeadNowhere(): void
	{
		$ends = (array) $this->pager()['ends'];
		if (count($ends) !== 2) {
			throw new RuntimeException('on the first page, first and previous still lead somewhere');
		}
	}

	#[Then('both ends lead somewhere')]
	public function endsLeadSomewhere(): void
	{
		$ends = (array) $this->pager()['ends'];
		if ($ends !== []) {
			throw new RuntimeException('an end control still leads nowhere: ' . implode(', ', $ends));
		}
	}

	// ── rows per page ────────────────────────────────────────────────────────────────────────

	#[Then('Limit is a list, not a field to type in')]
	public function limitIsAList(): void
	{
		$limit = $this->limit();
		if ($limit['tag'] !== 'SELECT') {
			throw new RuntimeException("Limit is a {$limit['tag']}, not a list to pick from");
		}
	}

	#[Then('it opened on :value')]
	public function limitOpenedOn(string $value): void
	{
		$limit = $this->limit();
		if ($limit['value'] !== $value) {
			throw new RuntimeException("the list opened on '{$limit['value']}', not the $value the page was showing");
		}
	}

	#[Then('it offers at least :count sizes')]
	public function limitOffers(int $count): void
	{
		$limit = $this->limit();
		if (count((array) $limit['options']) < $count) {
			throw new RuntimeException('the sizes offered are ' . implode(', ', (array) $limit['options']));
		}
	}

	#[Then('the list came back on :value')]
	public function limitCameBackOn(string $value): void
	{
		$this->limitOpenedOn($value);
	}

	// ── resizing a column ────────────────────────────────────────────────────────────────────

	/** Grabbed beside a data row rather than on the heading: the grip runs the height of the
	 * column, and that is the point of it.
	 */
	#[When('I drag the :column column :pixels pixels wider')]
	public function dragColumn(string $column, int $pixels): void
	{
		$this->dragged = $column;
		$this->before = $this->columns($column);
		if ($this->before['grip'] === null) {
			throw new RuntimeException("no resize grip was added to the $column column");
		}
		$this->drag((array) $this->before['grip'], $pixels);
		$this->after = $this->columns($column);
	}

	/** Widening a column whose values were already cut re-runs the query, in place: there is a url
	 * to wait for but no new document.
	 */
	#[When('I drag the :column column :pixels pixels wider and the query runs again')]
	public function dragColumnAndRefetch(string $column, int $pixels): void
	{
		$this->dragged = $column;
		$this->before = $this->columns($column);
		$this->drag((array) $this->before['grip'], $pixels);
		$this->page->waitForURL('**text_length=**');
		$this->after = $this->columns($column);
	}

	#[Then('the column is at least :pixels pixels wider')]
	public function columnIsWider(int $pixels): void
	{
		$grew = $this->after['width'] - $this->before['width'];
		if ($grew < $pixels) {
			throw new RuntimeException(sprintf(
				'the drag widened the column by %d, not %d (%d -> %d)',
				$grew,
				$pixels,
				$this->before['width'],
				$this->after['width'],
			));
		}
	}

	/** The table grows instead of the neighbours shrinking, which is what a table-layout that
	 * distributes would do.
	 */
	#[Then('the other columns kept their width')]
	public function otherColumnsKeptWidth(): void
	{
		foreach ((array) $this->after['others'] as $name => $width) {
			$was = ((array) $this->before['others'])[$name] ?? null;
			if ($was !== null && abs($width - $was) > 2) {
				throw new RuntimeException("the $name column moved with the drag ($was -> $width)");
			}
		}
	}

	#[Then('the table grew with it')]
	public function tableGrew(): void
	{
		if ($this->after['table'] - $this->before['table'] < 120) {
			throw new RuntimeException(sprintf(
				'the table did not grow with the column (%d -> %d)',
				$this->before['table'],
				$this->after['table'],
			));
		}
	}

	#[Then('the table scrolls inside the content panel')]
	public function tableScrollsInPanel(): void
	{
		if ($this->after['contentScrolls'] !== true || $this->after['windowScrolls'] !== false) {
			throw new RuntimeException('the widened table pushed the whole window sideways instead');
		}
	}

	/** Adminer's tableClick is bound to the table, so a click reaching it from the grip ticks the
	 * heading row's box — which is every row selected.
	 */
	#[Then('no rows were selected')]
	public function noRowsSelected(): void
	{
		if ($this->after['checked'] > 0) {
			throw new RuntimeException("the drag selected rows ({$this->after['checked']} checkboxes ticked)");
		}
	}

	#[Then('the grip runs the height of the column')]
	public function gripRunsTheColumn(): void
	{
		if ($this->before['gripHeight'] < 200) {
			throw new RuntimeException("the grip is only {$this->before['gripHeight']}px tall, not the column");
		}
	}

	/** It stops where the list does rather than running down over adminer's sticky row actions —
	 * margin included, because that gap is the footer's own background shadow.
	 */
	#[Then('the grip stops where the list does')]
	public function gripStopsAtTheList(): void
	{
		if ($this->before['pastFooter'] > 1) {
			throw new RuntimeException("the grip runs {$this->before['pastFooter']}px past the row actions");
		}
	}

	#[Then('Text length was left alone')]
	public function textLengthUnchanged(): void
	{
		if ($this->after['textLength'] !== $this->before['textLength']) {
			throw new RuntimeException(sprintf(
				'a column that already fits raised Text length anyway (%d -> %d)',
				$this->before['textLength'],
				$this->after['textLength'],
			));
		}
	}

	#[Then('Text length was raised to cover the column')]
	public function textLengthRaised(): void
	{
		if ($this->after['textLength'] <= $this->before['textLength']) {
			throw new RuntimeException("the widened column did not raise Text length (still {$this->after['textLength']})");
		}
		// The number is the column's width in its own characters, so it has to clear that width in
		// the widest plausible ones — measuring the wrong column's font reads as a pass at 101.
		if ($this->after['textLength'] < $this->after['width'] / 12) {
			throw new RuntimeException(sprintf(
				'Text length %d is too small for a %dpx column',
				$this->after['textLength'],
				$this->after['width'],
			));
		}
	}

	/** Raising the number is no use unless the query runs again, and the proof of that is on
	 * screen: the values in the widened column are longer than the ones they replaced.
	 */
	#[Then('longer values arrived')]
	public function longerValuesArrived(): void
	{
		if ($this->after['longestValue'] <= $this->before['longestValue']) {
			throw new RuntimeException(
				"the re-run fetched no more text (longest value still {$this->after['longestValue']} characters)",
			);
		}
	}

	#[Then('the column kept the width it was dragged to')]
	public function columnKeptDraggedWidth(): void
	{
		$now = $this->columns((string) $this->dragged);
		if (abs($now['width'] - $this->after['width']) > 4) {
			throw new RuntimeException("the column is {$now['width']}px, not the {$this->after['width']}px it was dragged to");
		}
	}

	#[Then('the column is still at the dragged width')]
	public function columnStillAtDraggedWidth(): void
	{
		$this->columnKeptDraggedWidth();
	}

	/** Column widths are the session's, not the durable file's — which is the whole point of where
	 * they are kept.
	 */
	/** The durable file is where a dragged sidebar and edit field land, under user_resized_px. A
	 * column is not one of them, and looking there rather than for the word "column" anywhere in
	 * the file is what keeps this from matching the json-column plugin's own name.
	 */
	#[Then('no column width reached the stored settings')]
	public function noColumnWidthStored(): void
	{
		/** @var array<string, int> $resized */
		$resized = e2e_settings($this->fix)['user_resized_px'] ?? [];
		foreach (array_keys($resized) as $key) {
			if (str_contains((string) $key, 'column')) {
				throw new RuntimeException('a column width reached settings.json: ' . json_encode($resized));
			}
		}
	}

	// ── resizing a field, and the sidebar ────────────────────────────────────────────────────

	/** The field's own native resize grip, in its bottom-right corner. */
	#[When('I drag the first field :pixels pixels wider')]
	public function dragFirstField(int $pixels): void
	{
		$this->before = $this->fields();
		if (count((array) $this->before['widths']) < 2) {
			throw new RuntimeException('the edit form has fewer than two visible fields to resize');
		}
		$this->drag((array) $this->before['grip'], $pixels);
		$this->after = $this->fields();
	}

	#[Then('the field is at least :pixels pixels wider')]
	public function fieldIsWider(int $pixels): void
	{
		$widths = (array) $this->after['widths'];
		$was = (array) $this->before['widths'];
		if ($widths[0] - $was[0] < $pixels) {
			throw new RuntimeException(sprintf('the drag did not widen the field (%.0f -> %.0f)', $was[0], $widths[0]));
		}
	}

	/** The point of the property: the fields nobody touched moved with it. A few pixels of slack,
	 * because JUSH's <pre> carries its own border and padding outside the width.
	 */
	#[Then('every other field on the form followed it')]
	public function everyFieldFollowed(): void
	{
		$widths = (array) $this->after['widths'];
		foreach ($widths as $i => $width) {
			if (abs($width - $widths[0]) > 12) {
				throw new RuntimeException(sprintf('field %d stayed at %.0f, not the dragged %.0f', $i, $width, $widths[0]));
			}
		}
	}

	#[When('I drag the sidebar handle :pixels pixels right')]
	public function dragSidebar(int $pixels): void
	{
		$handle = $this->page->evaluate(
			// language=JavaScript
			"() => {
				const h = document.querySelector('#ad-sidebar-resizer');
				if (!h) { return null; }
				const r = h.getBoundingClientRect();
				return { x: r.x + r.width / 2, y: r.y + r.height / 2 };
			}",
		);
		if (!is_array($handle)) {
			throw new RuntimeException('the resize handle was not inserted');
		}
		$this->before = ['sidebar' => $this->sidebarWidth()];
		$this->drag($handle, $pixels);
		$this->after = ['sidebar' => $this->sidebarWidth()];
	}

	#[Then('the sidebar is at least :pixels pixels wider')]
	public function sidebarIsWider(int $pixels): void
	{
		if ($this->after['sidebar'] - $this->before['sidebar'] < $pixels) {
			throw new RuntimeException(sprintf(
				'the drag did not widen the sidebar (%.0f -> %.0f)',
				$this->before['sidebar'],
				$this->after['sidebar'],
			));
		}
	}

	/** The accessible way to move a splitter, and it has to move it too. */
	#[When('I nudge the sidebar handle left with the keyboard')]
	public function nudgeSidebar(): void
	{
		$this->page->locator(
			// language=JQuery-CSS
			'#ad-sidebar-resizer',
		)->focus();
		$this->before = ['sidebar' => $this->sidebarWidth()];
		for ($i = 0; $i < 5; $i++) {
			$this->page->keyboard()->press('ArrowLeft');
		}
		$this->after = ['sidebar' => $this->sidebarWidth()];
	}

	#[Then('the sidebar is narrower')]
	public function sidebarIsNarrower(): void
	{
		if ($this->after['sidebar'] >= $this->before['sidebar']) {
			throw new RuntimeException('ArrowLeft did not narrow the sidebar');
		}
	}

	/** sidebar-resize.js adds the handle to the row after the first paint, so any room it takes is
	 * the content drawn and then pushed aside. Measured with it and without, not by frames, which
	 * would depend on when the browser happens to paint. */
	#[Then('the handle takes no room from the content')]
	public function handleTakesNoRoom(): void
	{
		$x = $this->page->evaluate(
			// language=JavaScript
			'() => {
				const x = () => document.querySelector("#content").getBoundingClientRect().x;
				const withHandle = x();
				document.querySelector("#ad-sidebar-resizer").remove();
				return [withHandle, x()];
			}',
		);
		if (!is_array($x) || $x[0] !== $x[1]) {
			throw new RuntimeException('the handle moved the content from ' . json_encode($x));
		}
	}

	/** sendBeacon is fire-and-forget, so the file appears a beat after mouseup with no response to
	 * await: poll for it rather than reading once.
	 */
	#[Then('the :what width is stored, matching what is on screen')]
	public function widthIsStored(string $what): void
	{
		$rendered = $what === 'sidebar' ? $this->after['sidebar'] : ((array) $this->after['widths'])[0];
		$stored = null;
		for ($i = 0; $i < 30; $i++) {
			$settings = e2e_settings($this->fix);
			/** @var array<string, int> $resized */
			$resized = $settings['user_resized_px'] ?? [];
			if (isset($resized[$what])) {
				$stored = (int) $resized[$what];
				break;
			}
			usleep(100_000);
		}
		if ($stored === null) {
			throw new RuntimeException("the $what width was not persisted to settings.json");
		}
		// The <pre> JUSH swaps in carries its own border and padding outside the width, so the
		// slack here is the same as the one the fields are compared with.
		if (abs($stored - $rendered) > 12) {
			throw new RuntimeException(sprintf('the stored width %d does not match the rendered %.0f', $stored, $rendered));
		}
	}

	/** A fresh page must open at the stored width before any script runs — head() emits it into
	 * the initial HTML, so the property is already set on load.
	 */
	#[Then('a fresh page opens the :what at the stored width')]
	public function freshPageOpensAtStoredWidth(string $what): void
	{
		/** @var array<string, int> $resized */
		$resized = e2e_settings($this->fix)['user_resized_px'] ?? [];
		$stored = (int) ($resized[$what] ?? 0);
		// A page in the same browser context, so it carries the same session: a fresh context would
		// arrive at the login form, where the element being measured does not exist and the width
		// reads as zero — which looks exactly like the stored width never being applied.
		$cold = $this->page->context()->newPage();
		$cold->goto($this->page->url());
		$cold->waitForLoadState('networkidle');
		$width = (float) $cold->evaluate(
			// language=JavaScript
			'(selector) => document.querySelector(selector).getBoundingClientRect().width',
			$what === 'sidebar' ? '#foot' : '#form > table.layout textarea',
		);
		$cold->close();
		if ($width <= 0) {
			throw new RuntimeException("nothing to measure on the fresh page — is it the $what page at all?");
		}
		if (abs($width - $stored) > 12) {
			throw new RuntimeException(sprintf('a fresh page opened at %.0f, not the stored %d', $width, $stored));
		}
	}

	// ── the sidebar's actions ────────────────────────────────────────────────────────────────

	/** Each found by the name a screen reader reads, which is adminer's label through the title,
	 * showing no text of its own and drawn from an icon file — a path that stopped resolving
	 * would leave a blank button and the name still passing. */
	#[Then('the sidebar offers :actions, each an icon')]
	public function sidebarActionsAreIcons(string $actions): void
	{
		$menu = $this->page->locator(
			// language=JQuery-CSS
			'#menu .links',
		);
		foreach (explode(', ', $actions) as $name) {
			$link = $menu->getByRole('link', ['name' => $name, 'exact' => true]);
			if ($link->count() !== 1) {
				throw new RuntimeException("the sidebar has {$link->count()} links named '$name'");
			}
			$drawn = (bool) $link->evaluate(
				// language=JavaScript
				'(a) => a.textContent.trim() === "" && getComputedStyle(a.querySelector(".ad-icon")).maskImage.includes("icons/")',
			);
			if (!$drawn) {
				throw new RuntimeException("'$name' in the sidebar is not an icon");
			}
		}
	}

	/** A label's text is hidden by zeroing its font, so the select's own size is what can break. */
	#[Then('the database and schema pickers fill their row, with no "DB:" or "Schema:" in front of them')]
	public function databasePickersUnlabelled(): void
	{
		/** @var list<array{label: float, select: float, height: float, font: float}> $pickers */
		$pickers = $this->page->evaluate(
			// language=JavaScript
			'() => [...document.querySelectorAll("#dbs label")].map((label) => {
				const select = label.querySelector("select");
				return {
					label: label.getBoundingClientRect().width,
					select: select.getBoundingClientRect().width,
					height: select.getBoundingClientRect().height,
					font: parseFloat(getComputedStyle(select).fontSize),
				};
			})',
		);
		if (count($pickers) !== 2) {
			throw new RuntimeException('expected the database and schema pickers, found ' . count($pickers));
		}
		foreach ($pickers as $state) {
			if ($state['font'] < 10 || $state['height'] < 20 || $state['label'] - $state['select'] > 1) {
				throw new RuntimeException('a picker is mis-sized: ' . json_encode($state));
			}
		}
	}

	/** One row: before-paint/header.js moves the actions into the pickers' row, after them. */
	#[Then('the actions follow the pickers on their row')]
	public function actionsShareThePickersRow(): void
	{
		/** @var array{inside: bool, middle: float, actionMiddle: float, schemaRight: float, actionLeft: float} $state */
		$state = $this->page->evaluate(
			// language=JavaScript
			'() => {
				const row = document.querySelector("#dbs");
				// The last picker: the schema one, or the database one alone on the server\'s page.
				const schema = [...row.querySelectorAll("label select")].at(-1).getBoundingClientRect();
				const action = document.querySelector("#menu .links a").getBoundingClientRect();
				return {
					inside: row.contains(document.querySelector("#menu .links")),
					middle: schema.top + schema.height / 2,
					actionMiddle: action.top + action.height / 2,
					schemaRight: schema.right,
					actionLeft: action.left,
				};
			}',
		);
		if (!$state['inside'] || abs($state['middle'] - $state['actionMiddle']) > 1 || $state['actionLeft'] < $state['schemaRight']) {
			throw new RuntimeException('the actions are not after the pickers on one row: ' . json_encode($state));
		}
	}

	/** field-sizing: a short schema name is a short select, and a long one in the database picker
	 * is cut to fit rather than pushing the row past the panel. */
	#[Then('each picker is as wide as the name in it, and a long name stays inside the sidebar')]
	public function pickersFitTheirNames(): void
	{
		/** @var array{row: float, schema: float, overflow: float} $state */
		$state = $this->page->evaluate(
			// language=JavaScript
			'() => {
				const row = document.querySelector("#dbs");
				const schema = row.querySelector("select[name=ns]").getBoundingClientRect().width;
				const db = row.querySelector("select[name=db]");
				db.selectedOptions[0].textContent = "a_database_name_far_too_long_for_any_sidebar_to_hold";
				const last = [...row.querySelectorAll("label")].at(-1).getBoundingClientRect();
				return {
					row: row.clientWidth,
					schema,
					overflow: last.right - row.getBoundingClientRect().right,
				};
			}',
		);
		if ($state['schema'] > $state['row'] / 2.5 || $state['overflow'] > 0) {
			throw new RuntimeException('the pickers do not fit their names: ' . json_encode($state));
		}
	}

	#[When('I point at the schema picker')]
	public function pointAtSchemaPicker(): void
	{
		$this->page->locator(
			// language=JQuery-CSS
			'#dbs select[name="ns"]',
		)->hover();
	}

	#[Then('a tooltip says :text')]
	public function tooltipSays(string $text): void
	{
		$tip = $this->page->locator(
			// language=JQuery-CSS
			'#ad-tooltip:not([hidden])',
		);
		$tip->waitFor(['timeout' => 800]);
		if (trim($tip->textContent() ?? '') !== $text) {
			throw new RuntimeException("the tooltip says '{$tip->textContent()}'");
		}
	}

	#[When('I point at :name in the sidebar')]
	public function pointAtSidebarLink(string $name): void
	{
		$this->page->locator(
			// language=JQuery-CSS
			'#menu .links',
		)->getByRole('link', ['name' => $name, 'exact' => true])->hover();
	}

	/** Under a second, which is about what the native one takes; the title is lifted while
	 * hovered so the native one cannot also appear. */
	#[Then('a tooltip says :text right away, and the link keeps its name')]
	public function tooltipShows(string $text): void
	{
		$tip = $this->page->locator(
			// language=JQuery-CSS
			'#ad-tooltip:not([hidden])',
		);
		$tip->waitFor(['timeout' => 800]);
		if (trim($tip->textContent() ?? '') !== $text) {
			throw new RuntimeException("the tooltip says '{$tip->textContent()}'");
		}
		$hovered = $this->page->locator(
			// language=JQuery-CSS
			'#menu .links',
		)->getByRole('link', ['name' => $text, 'exact' => true]);
		if ($hovered->count() !== 1 || $hovered->getAttribute('title') !== null) {
			throw new RuntimeException("'$text' lost its name, or kept the title the system would show");
		}
	}

	/** Measured where each column starts, not where its box does: a row pads its own highlight
	 * and the list around it gives that back, so changing either alone moves the icons off. */
	#[Then('each table is marked with an icon, in line with the database name above')]
	public function tablesAreMarked(): void
	{
		/** @var array{unmarked: int, label: float, table: float} $state */
		$state = $this->page->evaluate(
			// language=JavaScript
			'() => {
				const names = [...document.querySelectorAll("#tables li > a:not(.select)")];
				const select = document.querySelector("#dbs select[name=db]");
				const first = names[0];
				return {
					unmarked: names.filter((a) => !getComputedStyle(a, "::before").maskImage.includes("icons/")).length,
					label: select.getBoundingClientRect().left + parseFloat(getComputedStyle(select).paddingLeft),
					table: first.getBoundingClientRect().left + parseFloat(getComputedStyle(first).paddingLeft),
				};
			}',
		);
		if ($state['unmarked'] > 0) {
			throw new RuntimeException("{$state['unmarked']} tables in the sidebar have no icon");
		}
		if (abs($state['table'] - $state['label']) > 0.5) {
			throw new RuntimeException("the first table icon starts at {$state['table']}px, the database name at {$state['label']}px");
		}
	}

	// ── the theme ────────────────────────────────────────────────────────────────────────────

	/** The theme's own token is only defined by our stylesheet, so a non-empty value proves the
	 * Adminer Desktop CSS actually loaded and applied — not merely that a page rendered.
	 */
	#[Then('the theme is applied')]
	public function themeIsApplied(): void
	{
		$accent = $this->page->evaluate(
			// language=JavaScript
			"() => getComputedStyle(document.documentElement).getPropertyValue('--ad-accent').trim()",
		);
		if (!is_string($accent) || $accent === '') {
			throw new RuntimeException('the theme is not applied, --ad-accent is empty');
		}
	}

	/** Both schemes are one set of light-dark() tokens resolved by color-scheme, so a real surface
	 * has to have resolved to this scheme's side. A non-empty token alone would pass even if
	 * resolution silently fell back to light on every run.
	 */
	#[Then('the surface resolves to the :scheme scheme')]
	public function surfaceResolvesTo(string $scheme): void
	{
		if ($this->surfaceIsDark() !== ($scheme === 'dark')) {
			throw new RuntimeException("the surface did not resolve to the $scheme scheme");
		}
	}

	#[Then('the emulated scheme is :scheme')]
	public function emulatedSchemeIs(string $scheme): void
	{
		$isDark = (bool) $this->page->evaluate(
			// language=JavaScript
			"() => matchMedia('(prefers-color-scheme: dark)').matches",
		);
		if ($isDark !== ($scheme === 'dark')) {
			throw new RuntimeException("prefers-color-scheme was not emulated as $scheme");
		}
	}

	/** The gear sits in the sidebar's scroll flow, by the logo. position: fixed would leave it
	 * hanging over the panel while everything it belongs to scrolls away.
	 */
	#[Then('the settings gear scrolls with the sidebar')]
	public function gearScrollsWithSidebar(): void
	{
		$moved = $this->page->evaluate(
			// language=JavaScript
			"() => {
				const menu = document.querySelector('#menu'), gear = document.querySelector('#desktop-gear');
				const top = gear.getBoundingClientRect().top;
				menu.scrollTop = 200;
				return top - gear.getBoundingClientRect().top;
			}",
		);
		if ((float) $moved < 150) {
			throw new RuntimeException("the settings gear did not scroll with the sidebar (moved {$moved}px)");
		}
	}

	// ── the settings dialog ──────────────────────────────────────────────────────────────────

	#[When('I open the settings dialog')]
	public function openSettingsDialog(): void
	{
		// Only if it is closed. Changing the language reopens it, and clicking the gear while the
		// modal is up means clicking an element behind the backdrop, which never becomes
		// actionable — the whole check timed out there rather than failing on anything it asserts.
		if (!$this->page->evaluate(
			// language=JavaScript
			"() => document.querySelector('#desktop-settings').open",
		)) {
			$this->page->locator(
				// language=JQuery-CSS
				'#desktop-gear',
			)->click();
			usleep(300_000); // showModal() animates, and its contents are display:none until it lands
		}
	}

	#[When('I pick the :density row density')]
	public function pickDensity(string $density): void
	{
		$this->page->locator(
			// language=JQuery-CSS
			"input[name=\"density\"][value=\"$density\"]",
		)->check(['force' => true]);
	}

	#[When('I force the :appearance appearance')]
	public function forceAppearance(string $appearance): void
	{
		$this->page->locator(
			// language=JQuery-CSS
			"input[name=\"appearance\"][value=\"$appearance\"]",
		)->check(['force' => true]);
	}

	/** Whichever gallery design is offered first, so this does not break when the catalogue changes. */
	#[When('I pick the first gallery design')]
	public function pickFirstDesign(): void
	{
		$design = $this->page->evaluate(
			// language=JavaScript
			'() => { const r = [...document.querySelectorAll("input[name=design_light]")].find((x) => x.value); return r ? r.value : null; }',
		);
		if (!is_string($design) || $design === '') {
			throw new RuntimeException('no gallery design was offered to pick');
		}
		$this->dragged = $design; // reused as "what the last step picked"
		$this->page->locator(
			// language=JQuery-CSS
			"input[name=\"design_light\"][value=\"$design\"]",
		)->check(['force' => true]);
	}

	/** Set on the checkbox directly: what this guards is that Save persists it, not the browser's
	 * own checkbox toggle.
	 */
	#[When('I tick the :plugin plugin')]
	public function tickPlugin(string $plugin): void
	{
		$this->togglePlugin($plugin, true);
	}

	#[When('I untick the :plugin plugin')]
	public function untickPlugin(string $plugin): void
	{
		$this->togglePlugin($plugin, false);
	}

	#[When('I save the settings')]
	public function saveSettings(): void
	{
		$this->page->locator(
			// language=JQuery-CSS
			'#desktop-save',
		)->click();
		$this->page->waitForLoadState('networkidle');
	}

	/** The language <select> posts and reloads on its own onchange, without Playwright starting the
	 * click that would wait for it — so poll <html lang> until the reload lands rather than racing
	 * it with one evaluate.
	 */
	#[When('I switch the language to :lang')]
	public function switchLanguage(string $lang): void
	{
		$this->page->locator(
			// language=JQuery-CSS
			'#desktop-lang-slot select',
		)->selectOption($lang);
		for ($i = 0; $i < 30; $i++) {
			usleep(200_000);
			try {
				if ((string) $this->page->evaluate(
					// language=JavaScript
					'() => document.documentElement.lang',
				) === $lang) {
					return;
				}
			} catch (\Throwable $e) {
				continue; // mid-navigation; the context was torn down, try again
			}
		}
		throw new RuntimeException("the language never switched to $lang");
	}

	/** Playwright dismisses a native confirm() by default, which would answer no; this is the user
	 * pressing yes.
	 */
	#[When('I reset the settings to their defaults')]
	public function resetSettings(): void
	{
		$this->page->evaluate(
			// language=JavaScript
			'() => { window.confirm = () => true; }',
		);
		$this->page->locator(
			// language=JQuery-CSS
			'#desktop-reset',
		)->click();
		$this->page->waitForLoadState('networkidle');
		usleep(300_000); // the redirect lands before the unlink is visible to this process
	}

	#[Then('the settings dialog is named by its :title title')]
	public function settingsNamedByTitle(string $title): void
	{
		$name = (string) $this->page->evaluate(
			// language=JavaScript
			'() => { const d = document.querySelector("#desktop-settings"); const h = document.getElementById(d.getAttribute("aria-labelledby")); return h && h.checkVisibility() ? h.textContent.trim() : "(none visible)"; }',
		);
		if ($name !== $title) {
			throw new RuntimeException("the settings dialog is named '$name'");
		}
	}

	#[Then('the focus is on the settings dialog')]
	public function focusOnSettings(): void
	{
		$this->assertFocus('desktop-settings');
	}

	#[Then('the focus is back on the gear')]
	public function focusOnGear(): void
	{
		$this->assertFocus('desktop-gear');
	}

	#[When('I tab into the settings dialog')]
	public function tabIntoSettings(): void
	{
		$this->page->keyboard()->press('Tab');
	}

	/** The radio itself is hidden, so the ring is its label's — and it must be there for the keyboard. */
	#[Then('the focused tab shows a focus ring')]
	public function focusedTabRing(): void
	{
		$ring = (string) $this->page->evaluate(
			// language=JavaScript
			'() => { const r = document.activeElement; return r?.matches("#desktop-tabs input") ? getComputedStyle(r.nextElementSibling).boxShadow : "(focus on " + r?.id + ")"; }',
		);
		if ($ring === 'none' || str_starts_with($ring, '(')) {
			throw new RuntimeException("no focus ring on the tab: $ring");
		}
	}

	#[Then('the settings dialog is still open with :density picked')]
	public function settingsStillOpen(string $density): void
	{
		$state = (string) $this->page->evaluate(
			// language=JavaScript
			'() => document.querySelector("#desktop-settings").open && !document.querySelector("#ad-discard").open ? document.querySelector("input[name=density]:checked")?.value ?? "" : "(closed)"',
		);
		if ($state !== $density) {
			throw new RuntimeException("the settings dialog holds '$state'");
		}
	}

	#[Then('the settings dialog is closed')]
	public function settingsClosed(): void
	{
		$this->page->waitForFunction(
			// language=JavaScript
			'() => !document.querySelector("#desktop-settings").open',
		);
	}

	#[When('the OS asks for reduced motion')]
	public function reducedMotion(): void
	{
		$this->page->emulateMedia(['reducedMotion' => 'reduce']);
	}

	#[Then('the settings dialog does not fade')]
	public function settingsDoesNotFade(): void
	{
		$durations = (string) $this->page->evaluate(
			// language=JavaScript
			'() => { const d = document.querySelector("#desktop-settings"); return getComputedStyle(d).transitionDuration + " " + getComputedStyle(d, "::backdrop").transitionDuration; }',
		);
		if (preg_match('/[1-9]/', $durations)) {
			throw new RuntimeException("the dialog still transitions: $durations");
		}
	}

	/** A dragged width goes in through the api the page's own scripts post to: the reset has to
	 * forget what that stored too, not only the dialog's own fields.
	 */
	#[When('a dragged sidebar width has been stored')]
	public function storeADraggedWidth(): void
	{
		$this->page->evaluate(
			// language=JavaScript
			"() => navigator.sendBeacon(window.desktopApi.resize, new URLSearchParams({what: 'sidebar', width: '420'}))",
		);
		for ($i = 0; $i < 30 && !isset(e2e_settings($this->fix)['user_resized_px']); $i++) {
			usleep(100_000);
		}
	}

	// ── saved connections ────────────────────────────────────────────────────────────────────

	/** The address with no login in it: adminer answers with the login page, saved logins and all. */
	#[When('I go to the login page')]
	public function goToLoginPage(): void
	{
		$this->goto((string) $this->fix['base']);
	}

	#[Then('the saved connection is a card')]
	public function savedConnectionIsCard(): void
	{
		$cards = (int) $this->page->evaluate(
			// language=JavaScript
			'() => document.querySelectorAll("#ad-connections .ad-card").length',
		);
		if ($cards !== 1) {
			throw new RuntimeException("$cards cards on the login page");
		}
	}

	#[Then('the login form is folded away')]
	public function loginFormFolded(): void
	{
		if ($this->page->locator(
			// language=JQuery-CSS
			'input[name="auth[username]"]',
		)->isVisible()) {
			throw new RuntimeException('the login form is still showing');
		}
	}

	#[When('I ask for a new connection')]
	public function askForNewConnection(): void
	{
		$this->page->locator(
			// language=JQuery-CSS
			'#ad-new-connection',
		)->click();
	}

	#[Then('the login form is shown')]
	public function loginFormShown(): void
	{
		if (!$this->page->locator(
			// language=JQuery-CSS
			'input[name="auth[username]"]',
		)->isVisible()) {
			throw new RuntimeException('the login form stayed folded');
		}
	}

	#[When('I label the connection :name as :env in :color')]
	public function labelConnection(string $name, string $env, string $color): void
	{
		$this->page->locator(
			// language=JQuery-CSS
			'.ad-card-edit',
		)->click();
		$this->nameIt($name, $env, $color);
	}

	/** Both ways in show it, read in the same task as the submit and the click, before busy.js sends
	 * either on. The form is then kept from leaving by a listener on the document added after
	 * busy.js's; the card is let go, and its page waited for.
	 */
	#[Then('logging in, from the form or a card, shows the activity indicator')]
	public function connectingShowsIndicator(): void
	{
		$shown = $this->page->evaluate(
			// language=JavaScript
			'() => {
				const shown = () => document.querySelector("#ad-busy")?.matches(":popover-open") ?? false;
				document.addEventListener("submit", (event) => event.preventDefault());
				document.querySelector(\'[name="auth[driver]"]\').form.requestSubmit();
				const byForm = shown();
				window.desktopBusy(false);
				window.e2eLeaving = true;
				document.querySelector(".ad-card-link").click();
				return { byForm, byCard: shown() };
			}',
		);
		if ($shown !== ['byForm' => true, 'byCard' => true]) {
			throw new RuntimeException('the activity indicator did not show: ' . json_encode($shown));
		}
		$this->page->waitForFunction(
			// language=JavaScript
			'() => !window.e2eLeaving && document.readyState === "complete"',
		);
	}

	#[When('I log in with the password :password')]
	public function logInWith(string $password): void
	{
		$this->submitLogin((string) $this->fix['database'], $password);
		$this->page->waitForFunction(
			// language=JavaScript
			'() => !window.e2eSubmitted && document.readyState === "complete"',
		);
	}

	/** Sent, not waited out: the steps after this one look at the page while it waits. */
	#[When('I log in to a server that does not answer')]
	public function logInToSlowServer(): void
	{
		$this->submitLogin(e2e_slow_server(), 'any');
	}

	/** Shown, past its fade-in, and turning, while the page still waits on the login: as the
	 * page it left saw it (submitLogin()), so it holds whether this reads that page or the answer. */
	#[Then('the activity indicator turns while it waits')]
	public function indicatorTurns(): void
	{
		$this->page->waitForFunction(
			// language=JavaScript
			'() => sessionStorage.getItem("e2e-busy-turned") === "1"',
		);
	}

	/** A double-click, which goes straight to the rows (table-nav.js); read in the same task, as
	 * the page it then loads takes the indicator down again. */
	#[Then('opening :table from the sidebar shows the activity indicator')]
	public function sidebarShowsIndicator(string $table): void
	{
		$shown = $this->page->evaluate(
			// language=JavaScript
			'(table) => {
				const name = [...document.querySelectorAll("#tables li a:not(.select)")].find((a) => a.textContent.trim() === table);
				name.dispatchEvent(new MouseEvent("dblclick", { bubbles: true }));
				return document.querySelector("#ad-busy").matches(":popover-open");
			}',
			$table,
		);
		if ($shown !== true) {
			throw new RuntimeException("opening $table from the sidebar did not show the activity indicator");
		}
	}

	/** A link of adminer's content, which busy.js takes with no data-busy on it; read in the same
	 * task as the click, as the page it then loads takes the indicator down again. */
	#[Then('following :link in the content shows the activity indicator')]
	public function contentLinkShowsIndicator(string $link): void
	{
		if ($this->followContentLink($link) !== true) {
			throw new RuntimeException("following $link in the content did not show the activity indicator");
		}
	}

	/** An anchor on the same page loads nothing, so nothing would take the indicator down again. */
	#[Then('following :link in the content leaves the activity indicator down')]
	public function contentLinkLeavesIndicator(string $link): void
	{
		if ($this->followContentLink($link) !== false) {
			throw new RuntimeException("following $link in the content showed the activity indicator");
		}
	}

	/** A form of adminer's content, which busy.js takes with no data-busy on it; read in the same
	 * task as the click, then the page it sends waited for. */
	#[Then('pressing :button in the content shows the activity indicator')]
	public function contentButtonShowsIndicator(string $button): void
	{
		if ($this->pressInContent($button) !== true) {
			throw new RuntimeException("pressing $button in the content did not show the activity indicator");
		}
		$this->page->waitForFunction(
			// language=JavaScript
			'() => !window.e2eLeaving && document.readyState === "complete"',
		);
	}

	/** A download loads no page, so nothing would take the indicator down again. */
	#[Then('pressing :button in the content leaves the activity indicator down')]
	public function contentButtonLeavesIndicator(string $button): void
	{
		if ($this->pressInContent($button) !== false) {
			throw new RuntimeException("pressing $button in the content showed the activity indicator");
		}
	}

	/** Adminer streams the page as it runs the query, so the page is still loading while it does:
	 * looked at then, every 100 ms, as the page holds its first paint (and so its animation frames)
	 * until the sidebar, which comes after the result. */
	#[Then('running it turns the activity indicator beside the query until its result is in')]
	public function runningQueryShowsIndicator(): void
	{
		$this->page->evaluate(
			// language=JavaScript
			'() => {
				window.e2eLeaving = true;
				document.querySelector("#form input[type=submit]").click();
			}',
		);
		$this->page->waitForFunction(
			// language=JavaScript
			'() => {
				const query = !window.e2eLeaving && document.readyState === "loading" && document.querySelector("#sql-1");
				return query && getComputedStyle(query, "::after").animationName === "ad-spin";
			}',
			null,
			['polling' => 100],
		);
		$this->page->waitForFunction(
			// language=JavaScript
			'() => document.readyState === "complete"',
		);
		$done = $this->page->evaluate(
			// language=JavaScript
			'() => getComputedStyle(document.querySelector("#sql-1"), "::after").animationName',
		);
		if ($done !== 'none') {
			throw new RuntimeException("the query's indicator still turns after its result: $done");
		}
	}

	private function pressInContent(string $button): bool
	{
		return (bool) $this->page->evaluate(
			// language=JavaScript
			'(value) => {
				window.e2eLeaving = true;
				[...document.querySelectorAll("#content input[type=submit]")].find((b) => b.value === value).click();
				return document.querySelector("#ad-busy").matches(":popover-open");
			}',
			$button,
		);
	}

	#[Then('it lands on a URL with :text')]
	public function landsOn(string $text): void
	{
		$this->page->waitForURL("**$text**");
	}

	private function followContentLink(string $link): bool
	{
		return (bool) $this->page->evaluate(
			// language=JavaScript
			'(text) => {
				[...document.querySelectorAll("#content a")].find((a) => a.textContent.trim() === text).click();
				return document.querySelector("#ad-busy").matches(":popover-open");
			}',
			$link,
		);
	}

	/** The in-place swap paging and sorting go through (refresh.js): up while it waits, down once
	 * the rows are in, with no page load to take it down. */
	#[Then('fetching the rows shows the activity indicator until they are in')]
	public function refreshShowsIndicator(): void
	{
		$shown = $this->page->evaluate(
			// language=JavaScript
			'async () => {
				const shown = () => document.querySelector("#ad-busy").matches(":popover-open");
				const swapped = window.desktopRefresh();
				const during = shown();
				return { swapped: await swapped, during, after: shown() };
			}',
		);
		if ($shown !== ['swapped' => true, 'during' => true, 'after' => false]) {
			throw new RuntimeException('the activity indicator around the rows was ' . json_encode($shown));
		}
	}

	/** Another schema picked in adminer's menu, which sends its form on change. */
	#[Then('switching the schema shows the activity indicator')]
	public function schemaShowsIndicator(): void
	{
		$ns = $this->page->evaluate(
			// language=JavaScript
			'() => {
				const menu = document.querySelector("#dbs select[name=ns]");
				menu.value = [...menu.options].find((o) => o.value && o.value !== menu.value).value;
				menu.dispatchEvent(new Event("change", { bubbles: true }));
				return document.querySelector("#ad-busy").matches(":popover-open") ? menu.value : null;
			}',
		);
		if (!is_string($ns)) {
			throw new RuntimeException('switching the schema did not show the activity indicator');
		}
		$this->page->waitForURL("**ns=$ns**");
	}

	#[Then('the new connection says why, with what I typed still in it')]
	public function failedLoginInModal(): void
	{
		$this->page->waitForFunction(
			// language=JavaScript
			'() => document.querySelector("#ad-new-connection-dialog[open] .ad-modal-body > .error")',
		);
		$kept = $this->page->evaluate(
			// language=JavaScript
			'() => Object.fromEntries(["server", "username", "password"].map((name) =>
				[name, document.querySelector(`#ad-new-connection-dialog [name="auth[${name}]"]`).value]))',
		);
		if ($kept !== $this->typed) {
			throw new RuntimeException('the form came back as ' . json_encode($kept) . ', typed was ' . json_encode($this->typed));
		}
	}

	#[When('I connect to it again')]
	public function connectAgain(): void
	{
		e2e_login($this->page, $this->fix);
	}

	/** With the cursor in its name, and once the page has landed: the view transition in from the
	 * login covers the page while it runs, so a click then is a click outside the dialog, which
	 * closes it.
	 */
	#[Then('I am offered to name it')]
	public function offeredToNameIt(): void
	{
		$this->page->waitForFunction(
			// language=JavaScript
			'() => document.activeElement?.matches("#ad-connection-added[open] input[name=name]")
				&& !document.getAnimations().some((a) => a.effect?.pseudoElement?.startsWith("::view-transition"))',
		);
	}

	/** In whichever editor is open: a card's, or the one a new connection lands on. */
	#[When('I name it :name as :env in :color')]
	public function nameIt(string $name, string $env, string $color): void
	{
		$dialog = $this->page->locator(
			// language=JQuery-CSS
			'dialog.ad-connection-dialog[open]',
		);
		$dialog->locator(
			// language=JQuery-CSS
			'input[name="name"]',
		)->fill($name);
		$dialog->locator(
			// language=JQuery-CSS
			'select[name="env"]',
		)->selectOption($env);
		$dialog->locator(
			// language=JQuery-CSS
			"input[name=\"color\"][value=\"$color\"]",
		)->check(['force' => true]);
		$this->page->evaluate(
			// language=JavaScript
			'() => { window.e2eLeaving = true; document.querySelector("dialog[open] .ad-save").click(); }',
		);
		$this->page->waitForFunction(
			// language=JavaScript
			'() => !window.e2eLeaving && document.readyState === "complete"',
		);
	}

	#[Then('the logo is not a link')]
	public function logoNotLink(): void
	{
		if ((bool) $this->page->evaluate(
			// language=JavaScript
			'() => !!document.querySelector("a#h1")',
		)) {
			throw new RuntimeException('the logo is still a link');
		}
	}

	#[Then("the version links to adminer's releases")]
	public function versionLinksReleases(): void
	{
		$href = (string) $this->page->evaluate(
			// language=JavaScript
			'() => document.querySelector(".ad-versions a")?.getAttribute("href") ?? ""',
		);
		if ($href !== 'https://github.com/vrana/adminer/releases') {
			throw new RuntimeException("the version links to '$href'");
		}
	}

	/** One row at the foot of the login page, centred as a whole: its left and right gaps to the
	 * window match, and the gear sits right of the versions on the same line. */
	#[Then('the version and the gear are centred as a pair')]
	public function versionAndGearCentred(): void
	{
		$skew = $this->page->evaluate(
			// language=JavaScript
			"() => {
				const v = document.querySelector('.ad-versions').getBoundingClientRect();
				const g = document.querySelector('#desktop-gear').getBoundingClientRect();
				if (g.left < v.right || Math.abs((g.top + g.bottom) - (v.top + v.bottom)) > 2) {
					return 999;
				}
				return Math.abs(v.left - (document.documentElement.clientWidth - g.right));
			}",
		);
		if ((float) $skew > 2) {
			throw new RuntimeException("the version and the gear are off centre by {$skew}px");
		}
	}

	/** A mask clips what it covers to its own box, a fixed descendant too: the sidebar's fade under
	 * the lights hid the login page's foot, which sits in #menu but is pinned to the window's. */
	#[Then('nothing masks out the version and the gear')]
	public function versionAndGearUnmasked(): void
	{
		$masked = (string) $this->page->evaluate(
			// language=JavaScript
			"() => {
				for (let el = document.querySelector('.ad-login-foot'); el; el = el.parentElement) {
					const style = getComputedStyle(el);
					if ((style.maskImage || style.webkitMaskImage || 'none') !== 'none') {
						return el.id || el.tagName;
					}
				}
				return '';
			}",
		);
		if ($masked !== '') {
			throw new RuntimeException("#$masked masks the version and the gear");
		}
	}

	#[When('the window is :width by :height pixels')]
	public function windowIs(string $width, string $height): void
	{
		$this->page->setViewportSize((int) $width, (int) $height);
	}

	/** Nothing wider than the window: the page does not scroll sideways, and no card, button or
	 * open modal reaches past its edge. */
	#[Then('nothing runs past the window')]
	public function nothingPastWindow(): void
	{
		$over = (string) $this->page->evaluate(
			// language=JavaScript
			'() => {
				const out = [];
				if (document.documentElement.scrollWidth > innerWidth + 1) out.push("the page scrolls sideways");
				for (const e of document.querySelectorAll(".ad-card, .ad-card-action, .ad-env, dialog[open], dialog[open] button, dialog[open] input, dialog[open] select")) {
					const r = e.getBoundingClientRect();
					if (r.width && (r.left < -1 || r.right > innerWidth + 1)) out.push(e.id || e.className || e.tagName);
				}
				return [...new Set(out)].join(", ");
			}',
		);
		if ($over !== '') {
			throw new RuntimeException("past the window: $over");
		}
	}

	#[Then('Permanent login is ticked')]
	public function permanentTicked(): void
	{
		if (!$this->page->locator(
			// language=JQuery-CSS
			'input[name="auth[permanent]"]',
		)->isChecked()) {
			throw new RuntimeException('Permanent login is not ticked');
		}
	}

	#[When("I open the connection's editor")]
	public function openEditor(): void
	{
		$this->page->locator(
			// language=JQuery-CSS
			'.ad-card-edit',
		)->click();
		$this->page->waitForFunction(
			// language=JavaScript
			'() => document.querySelector("dialog.ad-connection-dialog[open]")',
		);
	}

	#[When('I pick the :env environment')]
	public function pickEnvironment(string $env): void
	{
		$this->page->locator(
			// language=JQuery-CSS
			'dialog[open] select[name="env"]',
		)->selectOption($env);
	}

	#[Then('the :color swatch is picked')]
	public function swatchPicked(string $color): void
	{
		$picked = (string) $this->page->evaluate(
			// language=JavaScript
			'() => document.querySelector("dialog[open] input[name=color]:checked")?.value ?? ""',
		);
		if ($picked !== $color) {
			throw new RuntimeException("the picked swatch is '$picked'");
		}
	}

	#[When('I type :name as its name')]
	public function typeName(string $name): void
	{
		$this->page->locator(
			// language=JQuery-CSS
			'dialog[open] input[name="name"]',
		)->fill($name);
	}

	#[When('I press Escape')]
	public function pressEscape(): void
	{
		$this->page->keyboard()->press('Escape');
	}

	/** By role and name, so it also proves the × is a button a screen reader announces as Close. */
	#[When('I click the close button')]
	public function clickClose(): void
	{
		$this->page->locator(
			// language=JQuery-CSS
			'dialog[open]',
		)->getByRole('button', ['name' => 'Close', 'exact' => true])->click();
	}

	#[Then('I am asked whether to discard the changes')]
	public function askedToDiscard(): void
	{
		$this->page->waitForFunction(
			// language=JavaScript
			'() => document.querySelector("#ad-discard")?.open',
		);
	}

	#[When('I keep editing')]
	public function keepEditing(): void
	{
		$this->page->locator(
			// language=JQuery-CSS
			'#ad-discard .ad-modal-footer [data-close]',
		)->click();
	}

	#[Then('the editor is still open with :name in it')]
	public function editorStillOpen(string $name): void
	{
		$value = (string) $this->page->evaluate(
			// language=JavaScript
			'() => document.querySelector("dialog.ad-connection-dialog[open] input[name=name]")?.value ?? "(closed)"',
		);
		if ($value !== $name || (bool) $this->page->evaluate(
			// language=JavaScript
			'() => document.querySelector("#ad-discard").open',
		)) {
			throw new RuntimeException("the editor holds '$value'");
		}
	}

	#[When('I log out of the connection')]
	public function logOutOfConnection(): void
	{
		$this->confirmCardAction('ad-connection-logout-1');
	}

	#[When('I forget the connection')]
	public function forgetConnection(): void
	{
		$this->confirmCardAction('ad-connection-forget-1');
	}

	#[Then('the card says it is logged out')]
	public function cardLoggedOut(): void
	{
		if (!(bool) $this->page->evaluate(
			// language=JavaScript
			'() => !!document.querySelector(".ad-card.ad-signed-out")',
		)) {
			throw new RuntimeException('the card is not marked logged out');
		}
	}

	#[Then('no connection is listed')]
	public function noConnectionListed(): void
	{
		$cards = (int) $this->page->evaluate(
			// language=JavaScript
			'() => document.querySelectorAll(".ad-card").length',
		);
		if ($cards !== 0 || !$this->page->locator(
			// language=JQuery-CSS
			'.ad-empty',
		)->isVisible()) {
			throw new RuntimeException("$cards cards, and no line saying there are none");
		}
	}

	#[Then('the card reads :name with a :env tag')]
	public function cardReads(string $name, string $env): void
	{
		$card = (string) $this->page->evaluate(
			// language=JavaScript
			'() => document.querySelector("#ad-connections .ad-card").innerText',
		);
		if (!str_contains($card, $name) || !str_contains($card, $env)) {
			throw new RuntimeException("the card reads '$card'");
		}
	}

	#[When('I open the connection from its card')]
	public function openFromCard(): void
	{
		// Marked so the wait survives the navigation and resolves on the page it lands on — a
		// load-state wait right after the click returns on the login page that is still there.
		$this->page->evaluate(
			// language=JavaScript
			'() => { window.e2eLeaving = true; document.querySelector(".ad-card-link").click(); }',
		);
		$this->page->waitForFunction(
			// language=JavaScript
			'() => !window.e2eLeaving && document.readyState === "complete"',
		);
	}

	#[When('I click the connection badge')]
	public function clickConnectionBadge(): void
	{
		// A real click, not element.click(): it fails naming whatever lies over the badge.
		$this->page->locator(
			// language=JQuery-CSS
			'#ad-connection-badge .ad-badge-name',
		)->click();
		$this->page->waitForFunction(
			// language=JavaScript
			'() => document.querySelector("#ad-connections .ad-card") && document.readyState === "complete"',
		);
	}

	#[Then('the connection badge reads :name')]
	public function connectionBadgeReads(string $name): void
	{
		$badge = (string) $this->page->evaluate(
			// language=JavaScript
			'() => document.querySelector("#ad-connection-badge")?.innerText ?? ""',
		);
		if (!str_contains($badge, $name)) {
			throw new RuntimeException("the badge reads '$badge'");
		}
	}

	#[Then('the body carries the :class class')]
	public function bodyCarriesClass(string $class): void
	{
		$body = (string) $this->page->evaluate(
			// language=JavaScript
			'() => document.body.className',
		);
		if (!str_contains($body, $class)) {
			throw new RuntimeException("the body class is '$body'");
		}
	}

	#[Then('the body does not carry the :class class')]
	public function bodyDoesNotCarryClass(string $class): void
	{
		$body = (string) $this->page->evaluate(
			// language=JavaScript
			'() => document.body.className',
		);
		if (in_array($class, explode(' ', $body), true)) {
			throw new RuntimeException("the body class is '$body'");
		}
	}

	#[Then('the surface is dark')]
	public function surfaceIsDarkStep(): void
	{
		if (!$this->surfaceIsDark()) {
			throw new RuntimeException('the surface is not dark');
		}
	}

	#[Then('the override renders dark under a light OS')]
	public function overrideRendersDark(): void
	{
		$osDark = (bool) $this->page->evaluate(
			// language=JavaScript
			"() => matchMedia('(prefers-color-scheme: dark)').matches",
		);
		if ($osDark) {
			throw new RuntimeException('a light OS context is needed to prove the override');
		}
		if (!$this->surfaceIsDark()) {
			throw new RuntimeException('the Dark override did not render dark under a light OS');
		}
	}

	#[Then('the chosen design is linked')]
	public function designIsLinked(): void
	{
		$design = (string) $this->dragged;
		$linked = (bool) $this->page->evaluate(
			// language=JavaScript
			'(d) => [...document.querySelectorAll("link[rel=stylesheet]")].some((l) => (l.getAttribute("href") || "").includes(d))',
			$design,
		);
		if (!$linked) {
			throw new RuntimeException("the chosen design ($design) did not save");
		}
	}

	#[Then(':key is stored as :value')]
	public function settingIsStored(string $key, string $value): void
	{
		$stored = e2e_settings($this->fix);
		if (($stored[$key] ?? null) !== $value) {
			throw new RuntimeException("$key is " . json_encode($stored[$key] ?? null) . ' in settings.json');
		}
	}

	#[Then('the :plugin plugin is stored as enabled')]
	public function pluginStoredEnabled(string $plugin): void
	{
		if (!str_contains((string) json_encode(e2e_settings($this->fix)), "\"$plugin\"")) {
			throw new RuntimeException("enabling '$plugin' did not save");
		}
	}

	#[Then('the :plugin plugin is no longer stored')]
	public function pluginNotStored(string $plugin): void
	{
		if (str_contains((string) json_encode(e2e_settings($this->fix)), "\"$plugin\"")) {
			throw new RuntimeException("disabling '$plugin' did not save");
		}
	}

	#[Then('the page comes back in :lang')]
	public function pageComesBackIn(string $lang): void
	{
		$html = (string) $this->page->evaluate(
			// language=JavaScript
			'() => document.documentElement.lang',
		);
		if ($html !== $lang) {
			throw new RuntimeException("the page is in '$html'");
		}
	}

	#[Then('nothing is stored any more')]
	public function nothingIsStored(): void
	{
		$file = $this->fix['data'] . '/settings.json';
		clearstatcache(true, $file);
		if (is_file($file)) {
			throw new RuntimeException('settings.json survived: ' . (string) file_get_contents($file));
		}
	}

	// ── the import dropzone ──────────────────────────────────────────────────────────────────

	#[Then('the import dropzone was never built')]
	public function dropzoneNeverBuilt(): void
	{
		if ($this->page->evaluate(
			// language=JavaScript
			"() => document.getElementById('ad-import-drop') === null",
		) !== true) {
			throw new RuntimeException('the dropzone overlay leaked onto a page that is not the import page');
		}
	}

	#[Then('the dropzone is ready and out of the way')]
	public function dropzoneReady(): void
	{
		$ready = (array) $this->page->evaluate(
			// language=JavaScript
			"() => {
				const input = document.querySelector('input[type=\"file\"][name=\"sql_file[]\"]');
				const overlay = document.getElementById('ad-import-drop');
				const strings = JSON.parse(document.getElementById('ad-i18n')?.textContent || '{}');
				return {
					input: !!input,
					overlay: !!overlay,
					hidden: overlay ? getComputedStyle(overlay).visibility === 'hidden' : null,
					label: overlay ? overlay.textContent : null,
					served: strings['import.drop_hint'] ?? null,
				};
			}",
		);
		if ($ready['input'] !== true) {
			throw new RuntimeException("Adminer's sql_file[] upload input was not found on the import page");
		}
		if ($ready['overlay'] !== true) {
			throw new RuntimeException('the dropzone overlay was not created on the import page');
		}
		if ($ready['hidden'] !== true) {
			throw new RuntimeException('the dropzone overlay was visible before any drag');
		}
		// The label is localized server-side: it has to come from the #ad-i18n block
		// AdminerDesktop emits, not from the script's English fallback.
		if (($ready['served'] ?? '') === '' || $ready['label'] !== $ready['served']) {
			throw new RuntimeException('the dropzone label did not come from #ad-i18n: '
				. json_encode($ready['label']) . ' against ' . json_encode($ready['served']));
		}
	}

	/** The same DataTransfer is kept on the window, so the drop below is the tail of one
	 * continuous drag.
	 */
	#[When('I drag a file over the window')]
	public function dragFileOverWindow(): void
	{
		$raised = $this->page->evaluate(
			// language=JavaScript
			"() => {
				const dt = new DataTransfer();
				dt.items.add(new File(['SELECT 1;\\n'], 'dropped-dump.sql', { type: 'application/sql' }));
				window.__adDrag = dt;
				const fire = (t) =>
					window.dispatchEvent(new DragEvent(t, { bubbles: true, cancelable: true, dataTransfer: dt }));
				fire('dragenter');
				fire('dragover');
				const o = document.getElementById('ad-import-drop');
				return o.classList.contains('dragging') && getComputedStyle(o).visibility === 'visible';
			}",
		);
		$this->after = ['raised' => $raised];
		usleep(200_000); // let the 0.12s fade-in finish, so a screenshot shows it at full opacity
	}

	#[Then('the drop affordance is raised')]
	public function dropAffordanceRaised(): void
	{
		if ($this->after['raised'] !== true) {
			throw new RuntimeException('dragging a file over the page did not raise the drop affordance');
		}
	}

	#[When('I drop it')]
	public function dropTheFile(): void
	{
		$this->after = (array) $this->page->evaluate(
			// language=JavaScript
			"() => {
				const dt = window.__adDrag;
				const notPrevented = window.dispatchEvent(
					new DragEvent('drop', { bubbles: true, cancelable: true, dataTransfer: dt }),
				);
				const input = document.querySelector('input[name=\"sql_file[]\"]');
				const o = document.getElementById('ad-import-drop');
				return {
					count: input.files.length,
					name: input.files.length ? input.files[0].name : null,
					prevented: !notPrevented,
					hidden: !o.classList.contains('dragging'),
				};
			}",
		);
	}

	#[Then("the file landed on Adminer's own upload input")]
	public function fileLandedOnInput(): void
	{
		if ($this->after['count'] !== 1 || $this->after['name'] !== 'dropped-dump.sql') {
			throw new RuntimeException("the dropped file did not land on Adminer's sql_file[] input");
		}
	}

	/** dispatchEvent returns false when a handler preventDefaults a cancelable event, which is the
	 * browser's own "navigate to the file" being called off.
	 */
	#[Then('the browser did not navigate to the file')]
	public function browserDidNotNavigate(): void
	{
		if ($this->after['prevented'] !== true) {
			throw new RuntimeException("the drop did not cancel the browser's navigate-to-file default");
		}
	}

	#[Then('the overlay fell away')]
	public function overlayFellAway(): void
	{
		if ($this->after['hidden'] !== true) {
			throw new RuntimeException('the overlay stayed up after the drop');
		}
	}

	// ── the plugins on a form ────────────────────────────────────────────────────────────────

	#[Then('the :field field is a dropdown')]
	public function fieldIsDropdown(string $field): void
	{
		$tag = $this->fieldTag($field);
		if ($tag !== 'SELECT') {
			throw new RuntimeException("$field is a $tag — the plugin is not applying");
		}
	}

	/** Past the limit the plugin was constructed with it returns nothing, so Adminer's plain input
	 * stands. A dropdown here would mean the argument never arrived and the whole table was read.
	 */
	#[Then('the :field field is left as a plain input')]
	public function fieldIsPlainInput(string $field): void
	{
		$tag = $this->fieldTag($field);
		if ($tag === 'SELECT') {
			throw new RuntimeException("$field became a dropdown — the limit did not reach the plugin");
		}
		if ($tag === 'MISSING') {
			throw new RuntimeException("$field is not on the edit form at all");
		}
	}

	// ── Adminer's JSON editor ────────────────────────────────────────────────────────────────

	/** JUSH hides the textarea and puts its highlighted <pre> in the same cell; the class names the
	 * language, so a JavaScript or plain editor would not pass for a JSON one.
	 */
	#[Then("the :field field is Adminer's JSON editor")]
	public function fieldIsJsonEditor(string $field): void
	{
		$editor = (string) $this->page->evaluate($this->fieldScript($field, "el.parentNode.querySelector('pre.jush-json') ? 'yes' : 'no'"));
		if ($editor !== 'yes') {
			throw new RuntimeException("$field has no JSON editor ($editor)");
		}
	}

	#[Then("the :field field is left as Adminer's plain textarea")]
	public function fieldIsPlainTextarea(string $field): void
	{
		$marker = (string) $this->page->evaluate($this->fieldScript($field, "el.tagName + (el.parentNode.querySelector('pre.jush') ? '+editor' : '')"));
		if ($marker !== 'TEXTAREA') {
			throw new RuntimeException("$field is $marker, not a plain textarea");
		}
	}

	/** What the editor shows is what it writes back to the hidden textarea, so the value is read there. */
	#[Then('the :field field is pretty-printed')]
	public function fieldIsPrettyPrinted(string $field): void
	{
		$value = (string) $this->page->evaluate($this->fieldScript($field, 'el.value'));
		if (!str_contains($value, "\n    \"")) {
			throw new RuntimeException("$field is not pretty-printed: " . var_export(substr($value, 0, 120), true));
		}
	}

	#[Then('the :field field kept its accents')]
	public function fieldKeptAccents(string $field): void
	{
		$value = (string) $this->page->evaluate($this->fieldScript($field, 'el.value'));
		if (!str_contains($value, 'Nováková')) {
			throw new RuntimeException("$field lost its unicode: " . var_export(substr($value, 0, 120), true));
		}
	}

	/** JUSH copies the width of the textarea it hides, so any font Adminer gives that textarea alone
	 * moves the editor off the width the rest of the form is on — past the form's edge, as 6.0's
	 * 110% monospace did, by some 90px. JUSH's own padding on its content-box <pre> is the few
	 * pixels allowed.
	 */
	#[Then('the :field editor is as wide as the :other field')]
	public function editorAsWideAs(string $field, string $other): void
	{
		$editor = (int) $this->page->evaluate($this->fieldScript($field, "el.parentNode.querySelector('pre.jush').offsetWidth"));
		$plain = (int) $this->page->evaluate($this->fieldScript($other, 'el.offsetWidth'));
		if (abs($editor - $plain) > 12) {
			throw new RuntimeException("the $field editor is {$editor}px, the $other field {$plain}px");
		}
	}

	// ── the plumbing ─────────────────────────────────────────────────────────────────────────

	/** A page in a context of its own — its own cookies, and the scheme the OS is pretending to be.
	 *
	 * The version cookie is what stops Adminer asking adminer.org whether a newer release is out: a
	 * context starts with no cookies, so every scenario asked again, and every
	 * waitForLoadState('networkidle') then waited on somebody else's server before a step could
	 * run. Only its presence is read, never the value.
	 *
	 * @param array<string, mixed> $options
	 */
	/** Open a card's confirmation by its button and answer it with its submit, waiting for the
	 * page the post redirects to. */
	private function confirmCardAction(string $dialog): void
	{
		$this->page->locator(
			// language=JQuery-CSS
			"[data-dialog=\"$dialog\"]",
		)->click();
		$this->page->waitForFunction(
			// language=JavaScript
			"() => document.getElementById('$dialog')?.open",
		);
		$this->page->evaluate(
			// language=JavaScript
			"() => { window.e2eLeaving = true; document.querySelector('#$dialog [type=submit]').click(); }",
		);
		$this->page->waitForFunction(
			// language=JavaScript
			'() => !window.e2eLeaving && document.readyState === "complete"',
		);
	}

	/** Fill the new connection as this driver's user and send it, marking the page it leaves
	 * (window.e2eSubmitted) so a step can tell the answer from the form.
	 *
	 * The page it leaves also watches its own indicator, on a timer, and notes in sessionStorage
	 * once it turns: polled from the test instead, a page already navigating away was missed on a
	 * CI runner now and then, the wait then ran into the answer, and the step timed out.
	 */
	private function submitLogin(string $server, string $password): void
	{
		$this->page->goto((string) $this->fix['base']);
		$this->page->locator(
			// language=JQuery-CSS
			'#ad-new-connection',
		)->click();
		$this->page->locator(
			// language=JQuery-CSS
			'select[name="auth[driver]"]',
		)->selectOption((string) $this->fix['driver']['key']);
		usleep(400_000); // the driver's rebuild of the fields, as in e2e_login()
		$this->typed = ['server' => $server, 'username' => (string) $this->fix['driver']['username'], 'password' => $password];
		foreach ($this->typed as $name => $value) {
			$this->page->locator(
				// language=JQuery-CSS
				"input[name=\"auth[$name]\"]",
			)->fill($value);
		}
		$this->page->evaluate(
			// language=JavaScript
			'() => {
				sessionStorage.removeItem("e2e-busy-turned");
				const watch = setInterval(() => {
					const busy = document.querySelector("#ad-busy:popover-open");
					if (busy && getComputedStyle(busy).opacity === "1"
						&& busy.querySelector(".ad-icon-spinner").getAnimations().some((a) => a.animationName === "ad-spin" && a.playState === "running")) {
						sessionStorage.setItem("e2e-busy-turned", "1");
						clearInterval(watch);
					}
				}, 50);
				window.e2eSubmitted = true;
				document.querySelector(\'[name="auth[driver]"]\').form.requestSubmit();
			}',
		);
	}

	private function assertFocus(string $id): void
	{
		$focused = (string) $this->page->evaluate(
			// language=JavaScript
			'() => document.activeElement?.id ?? ""',
		);
		if ($focused !== $id) {
			throw new RuntimeException("the focus is on '$focused', not #$id");
		}
	}

	private function newPage(array $options = []): PageInterface
	{
		$context = e2e_browser()->newContext($options + ['viewport' => ['width' => 1600, 'height' => 900]]);
		$context->addCookies([[
			'name' => 'adminer_version',
			'value' => '0',
			'domain' => '127.0.0.1',
			'path' => '/',
		]]);
		// Every uncaught script error of the scenario, on any page it went through: sessionStorage
		// outlives the navigations of one tab, a page's own variables do not. close() fails the
		// scenario on one of ours. A script that dies on load is silent otherwise — one did, and with
		// it the sidebar's kept scroll position, through a whole green run.
		$context->addInitScript(
			// language=JavaScript
			'addEventListener("error", (event) => {
				try {
					const errors = JSON.parse(sessionStorage.getItem("ad-e2e-errors") || "[]");
					errors.push(`${event.message} (${event.filename}:${event.lineno}:${event.colno})\n${event.error?.stack ?? ""}`);
					sessionStorage.setItem("ad-e2e-errors", JSON.stringify(errors));
				} catch {}
			});',
		);
		return $context->newPage();
	}

	/** Go to a page of the demo database.
	 * @param array<string, string> $params
	 */
	private function go(array $params): void
	{
		$this->goto(e2e_url($this->fix, $params));
	}

	private function goto(string $url): void
	{
		$this->page->goto($url);
		$this->page->waitForLoadState('networkidle');
		// Set on every navigation and only ever lost to a new document, which is what the in-place
		// swaps are here not to do.
		$this->page->evaluate(
			// language=JavaScript
			'() => { window.__adSameDocument = true; }',
		);
	}

	/** @param array<string, mixed> $at */
	private function drag(array $at, int $by): void
	{
		$mouse = $this->page->mouse();
		$mouse->move((float) $at['x'], (float) $at['y']);
		$mouse->down();
		$mouse->move((float) $at['x'] + $by, (float) $at['y'], ['steps' => 10]);
		$mouse->up();
	}

	private function togglePlugin(string $plugin, bool $on): void
	{
		$checked = $on ? 'true' : 'false';
		$this->page->evaluate(
			// language=JavaScript
			"() => { document.querySelector(\"input[name='plugins[]'][value='$plugin']\").checked = $checked; }",
		);
	}

	/** Wait for the rows themselves rather than for the url, which an in-place swap corrects a beat
	 * after it puts them on screen.
	 *
	 * @param array<string, mixed> $before
	 * @param callable(): array<string, mixed> $measure
	 * @return array<string, mixed>
	 */
	private function settled(array $before, callable $measure): array
	{
		$now = $before;
		for ($i = 0; $i < 40 && $now['first'] === $before['first']; $i++) {
			usleep(100_000);
			$now = $measure();
		}
		return $now;
	}

	/** Evaluate an expression with `el` bound to a field's editor. */
	private function fieldScript(string $field, string $expression): string
	{
		// language=JavaScript
		return "() => {
			const el = document.querySelector('form [name=\"fields[$field]\"]');
			if (!el) { return 'MISSING'; }
			return String($expression);
		}";
	}

	private function fieldTag(string $field): string
	{
		return (string) $this->page->evaluate($this->fieldScript($field, 'el.tagName'));
	}

	/** Whether a real surface — not a token, a surface — resolved to the dark side. */
	private function surfaceIsDark(): bool
	{
		return (bool) $this->page->evaluate(
			// language=JavaScript
			"() => {
				const el = document.querySelector('#content') || document.body;
				const [r, g, b] = getComputedStyle(el).backgroundColor.match(/\\d+/g).map(Number);
				return r + g + b < 200;
			}",
		);
	}

	private function sidebarWidth(): float
	{
		return (float) $this->page->evaluate(
			// language=JavaScript
			"() => document.querySelector('#foot').getBoundingClientRect().width",
		);
	}

	/** @return array<string, mixed> */
	private function list(): array
	{
		return (array) $this->page->evaluate(
			// language=JavaScript
			"() => ({
				first: (document.querySelector('#table tbody tr td:nth-child(3)')?.textContent ?? '').trim(),
				rows: document.querySelectorAll('#table tbody tr').length,
				highlighted: document.querySelectorAll('#table tbody code span[class^=jush]').length,
				sameDocument: window.__adSameDocument === true,
			})",
		);
	}

	/** @return array<string, mixed> */
	private function pager(): array
	{
		return (array) $this->page->evaluate(
			// language=JavaScript
			"() => {
				const steps = [...document.querySelectorAll('.ad-page-step')];
				const list = document.querySelector('.ad-page-select');
				const chip = document.querySelector('.ad-page-chip');
				return {
					steps: steps.length,
					ends: steps.filter((s) => s.tagName !== 'A').map((s) => s.textContent),
					pages: list ? list.options.length : 0,
					at: list ? list.value : '',
					total: (document.querySelector('.ad-page-total')?.textContent ?? '').trim(),
					range: (list?.selectedOptions[0]?.textContent ?? '').trim(),
					drawn: steps.filter((s) => (getComputedStyle(s, '::before').maskImage || '').includes('icons/')).length,
					chevron: chip ? (getComputedStyle(chip, '::after').maskImage || '').includes('icons/') : false,
					page: location.search.match(/[?&]page=(\\d+)/)?.[1] ?? '0',
				};
			}",
		);
	}

	/** @return array<string, mixed> */
	private function limit(): array
	{
		return (array) $this->page->evaluate(
			// language=JavaScript
			"() => {
				const field = document.querySelector(\"#form [name='limit']\");
				return {
					tag: field ? field.tagName : 'none',
					value: field ? field.value : '',
					options: field && field.options ? [...field.options].map((o) => o.value) : [],
				};
			}",
		);
	}

	/** One column's width and grip, every other column's width, and what the page around it is
	 * doing. Addressed by name, because a position is a different column per driver.
	 *
	 * @return array<string, mixed>
	 */
	private function columns(string $column): array
	{
		return (array) $this->page->evaluate(
			// language=JavaScript
			"(column) => {
				const ths = [...document.querySelectorAll('#table tr:first-child th')];
				const index = ths.findIndex((th) => th.id === `th[\${column}]`);
				if (index < 0) { return { missing: ths.map((th) => th.id).join(',') }; }
				const th = ths[index];
				const grip = th.querySelector('.ad-column-grip');
				const box = grip ? grip.getBoundingClientRect() : null;
				const content = document.querySelector('#content');
				const footer = document.querySelector('.footer');
				const others = {};
				ths.forEach((other, i) => {
					if (i !== index && other.id) { others[other.id] = Math.round(other.getBoundingClientRect().width); }
				});
				return {
					width: Math.round(th.getBoundingClientRect().width),
					others,
					// Deliberately not the header: the grip runs the height of the column, and grabbing
					// it beside a data row well below the header is the point of that.
					grip: box ? { x: box.right - 1, y: box.top + Math.min(200, box.height - 10) } : null,
					gripHeight: box ? Math.round(box.height) : 0,
					// How far it runs past adminer's sticky row actions, margin included: that gap is
					// the footer's own background shadow, painted over the rows behind it.
					pastFooter: box ? Math.round(
						box.bottom - (footer.getBoundingClientRect().top - Number.parseFloat(getComputedStyle(footer).marginTop))
					) : 0,
					textLength: Number(document.querySelector(\"input[name='text_length']\").value),
					table: Math.round(document.querySelector('#table').getBoundingClientRect().width),
					contentScrolls: content.scrollWidth > content.clientWidth,
					windowScrolls: document.documentElement.scrollWidth > document.documentElement.clientWidth,
					checked: [...document.querySelectorAll('#table input[type=checkbox]')].filter((c) => c.checked).length,
					// Highlighted values, so a row swapped in by a re-run is not plain text where the
					// one it replaced was coloured.
					highlighted: document.querySelectorAll('#table tbody code span[class^=jush]').length,
					// The longest value on show: what raising Text length is actually for.
					longestValue: Math.max(...[...document.querySelectorAll('#table tbody tr')]
						.map((tr) => (tr.cells[index + 1]?.textContent ?? '').length)),
				};
			}",
			$column,
		);
	}

	// ── the shortcuts tab ────────────────────────────────────────────────────────────────────

	#[When('I press the :key key')]
	public function press(string $key): void
	{
		$this->page->keyboard()->press($key);
		usleep(300_000); // showModal() animates, and its contents are display:none until it lands
	}

	#[When('I press :key in the first field')]
	public function pressInFirstField(string $key): void
	{
		$this->page->locator(
			// language=JQuery-CSS
			'#form [name^="fields["]',
		)->first()->press($key);
		usleep(300_000);
	}

	#[When('I open the shortcuts tab')]
	public function openShortcutsTab(): void
	{
		$this->page->locator(
			// language=JQuery-CSS
			'label[for="desktop-tab-shortcuts"]',
		)->click();
	}

	#[When('I open the AI access tab')]
	public function openMcpTab(): void
	{
		$this->page->locator(
			// language=JQuery-CSS
			'label[for="desktop-tab-mcp"]',
		)->click();
	}

	#[When('I tick the agent access switch')]
	public function tickMcp(): void
	{
		$this->page->locator(
			// language=JQuery-CSS
			'label[for="desktop-mcp"]',
		)->click();
	}

	#[When('I pick :label in the register box')]
	public function pickRegisterTab(string $label): void
	{
		$this->page->locator(
			// language=JQuery-CSS
			'.ad-mcp-register .ad-subtabs label',
		)->getByText($label, ['exact' => true])->click();
	}

	/** Visible ones only: the other sub-tab's commands are in the DOM too, hidden by CSS. */
	#[Then('the register box shows :command')]
	public function registerShows(string $command): void
	{
		$shown = (string) $this->page->evaluate(
			// language=JavaScript
			"() => [...document.querySelectorAll('.ad-mcp-register [data-copy-value]')]
				.filter((t) => t.checkVisibility()).map((t) => t.textContent).join('\\n')",
		);
		if (!str_contains($shown, $command)) {
			throw new RuntimeException("the register box shows:\n$shown");
		}
	}

	#[Then('the settings dialog is open on the shortcuts tab')]
	public function settingsOnShortcuts(): void
	{
		$state = (string) $this->page->evaluate(
			// language=JavaScript
			"() => {
				const dialog = document.querySelector('#desktop-settings');
				const panel = document.querySelector('#desktop-panel-shortcuts');
				return !dialog.open ? 'closed' : panel.checkVisibility() ? 'ok' : 'open on another tab';
			}",
		);
		if ($state !== 'ok') {
			throw new RuntimeException("the settings dialog is $state");
		}
	}

	// ── search everywhere ───────────────────────────────────────────────────────────────────

	/** Back to back, well inside the 300 ms search.js allows between them. */
	#[When('I press Shift twice')]
	public function pressShiftTwice(): void
	{
		$this->page->keyboard()->press('Shift');
		$this->page->keyboard()->press('Shift');
		$this->page->waitForFunction(
			// language=JavaScript
			"() => document.querySelector('#desktop-search').open",
		);
	}

	#[When('I type :text')]
	public function type(string $text): void
	{
		$this->page->keyboard()->type($text);
	}

	#[Then('the :table table is open')]
	public function tableIsOpen(string $table): void
	{
		$this->page->waitForURL("**select=$table**");
	}

	// ── the window without a title bar (macOS) ───────────────────────────────────────────────

	/** What the macOS launcher does to every page (installFrameless): the bindings it adds and
	 * the class it sets before anything paints. Stubs here, as Chromium has no launcher; a press
	 * that would move the window is counted instead. Chromium runs an init script before the
	 * parser has made <html>, so the class goes on the moment it does: waiting for readystatechange
	 * marked it after the page had parsed, and before-paint/header.js, which reads it, never saw it.
	 */
	#[Given('the window has no title bar')]
	public function windowHasNoTitleBar(): void
	{
		$this->page->addInitScript(
			// language=JavaScript
			'window.adDrags = 0;
			window.adDragWindow = () => { window.adDrags++; };
			window.adTitlebarDoubleClick = () => {};
			// The sidebar is see-through there, onto the system material the launcher puts behind the
			// page; Chromium has none, so the sidebar colour stands in, or axe reads its text on white.
			const mark = () => {
				document.documentElement.classList.add("ad-frameless");
				document.documentElement.style.background = "var(--ad-menu-bg)";
			};
			if (document.documentElement) {
				mark();
			} else {
				new MutationObserver((_, watch) => {
					if (document.documentElement) { mark(); watch.disconnect(); }
				}).observe(document, { childList: true });
			}',
		);
	}

	#[Then('the page\'s :links are buttons in the toolbar')]
	public function linksAreToolbarButtons(string $links): void
	{
		foreach (array_map('trim', explode(',', $links)) as $name) {
			$button = $this->page->locator(
				// language=JQuery-CSS
				'#breadcrumb .ad-toolbar-actions',
			)->getByRole('link', ['name' => $name, 'exact' => true]);
			$width = $button->evaluate(
				// language=JavaScript
				'(a) => [a.getBoundingClientRect().width, getComputedStyle(a, "::before").maskImage || getComputedStyle(a, "::before").webkitMaskImage]',
			);
			if (!is_array($width) || $width[0] < 30 || $width[0] > 50 || !str_contains((string) $width[1], 'tool-')) {
				throw new RuntimeException("\"$name\" is not an icon button in the toolbar: " . json_encode($width));
			}
		}
	}

	#[Then('no list of links is left under the title')]
	public function noLinksUnderTitle(): void
	{
		$left = $this->page->locator(
			// language=JQuery-CSS
			'#content > p.links',
		)->count();
		$moved = $this->page->locator(
			// language=JQuery-CSS
			'#breadcrumb .ad-toolbar-actions',
		)->count();
		if ($moved !== 1 || $left > 0 && $this->page->evaluate(
			// language=JavaScript
			'() => { let b = document.querySelector("#content > p.links")?.previousElementSibling; while (b?.matches("#ajaxstatus, .message, .error, script")) { b = b.previousElementSibling; } return !!b?.matches("h2, #breadcrumb"); }',
		)) {
			throw new RuntimeException("the page's links stayed under its title ($moved in the toolbar, $left left)");
		}
	}

	#[When('I click the toolbar\'s search')]
	public function clickToolbarSearch(): void
	{
		$this->page->locator(
			// language=JQuery-CSS
			'#breadcrumb .ad-toolbar',
		)->getByRole('button', ['name' => 'Search'])->click();
	}

	#[Then('the search box is open')]
	public function searchBoxIsOpen(): void
	{
		$this->page->waitForFunction(
			// language=JavaScript
			"() => document.querySelector('#desktop-search').open && document.activeElement === document.querySelector('#desktop-search input')",
		);
	}

	/** What the launcher adds once the window goes full screen (installFrameless): the class, set
	 * from the start of every page. */
	#[Given('the window is full screen')]
	public function windowIsFullScreen(): void
	{
		$this->page->addInitScript(
			// language=JavaScript
			'const mark = () => document.documentElement.classList.add("ad-fullscreen");
			if (document.documentElement) {
				mark();
			} else {
				new MutationObserver((_, watch) => {
					if (document.documentElement) { mark(); watch.disconnect(); }
				}).observe(document, { childList: true });
			}',
		);
	}

	/** And waits for the app to have stored it: the toggle's beacon and the next page are two
	 * requests that race, and a page served first would show the sidebar again. */
	#[When('I hide the sidebar')]
	#[When('I show the sidebar')]
	public function toggleSidebar(): void
	{
		$hidden = $this->page->evaluate(
			// language=JavaScript
			"() => !document.body.classList.contains('ad-sidebar-hidden')",
		);
		$this->page->getByRole('button', ['name' => 'Show or hide the sidebar'])->click();
		$this->hidSidebar = true;
		for ($i = 0; $i < 50 && (e2e_settings($this->fix)['sidebar_hidden'] ?? false) !== $hidden; $i++) {
			usleep(100_000);
		}
		if ((e2e_settings($this->fix)['sidebar_hidden'] ?? false) !== $hidden) {
			throw new RuntimeException('the sidebar\'s state was never stored');
		}
	}

	/** Drawn, inside the viewport and not covered: what sits at each one's centre is that control.
	 * And none on another — one drawn on top passes its own check, and hides the one below. */
	#[Then('the :controls are on screen')]
	public function controlsOnScreen(string $controls): void
	{
		$selectors = [
			'sidebar' => '#foot',
			'tables' => '#tables li:first-child',
			'sidebar toggle' => '#ad-sidebar-toggle',
			'settings gear' => '#desktop-gear',
			'toolbar' => '#breadcrumb .ad-toolbar',
			'path' => '#breadcrumb .ad-breadcrumb-current',
			'search' => '#breadcrumb .ad-toolbar-search',
			'path bar' => '#ad-pathbar',
		];
		$names = array_map('trim', explode(',', $controls));
		foreach ($names as $name) {
			$off = $this->page->evaluate(
				// language=JavaScript
				"(selector) => {
					const el = document.querySelector(selector);
					if (!el) { return 'missing'; }
					if (!el.checkVisibility({ visibilityProperty: true, opacityProperty: true })) { return 'hidden'; }
					const r = el.getBoundingClientRect();
					if (r.width < 1 || r.height < 1 || r.left < 0 || r.top < 0 || r.right > innerWidth || r.bottom > innerHeight) {
						return 'outside the window: ' + JSON.stringify(r);
					}
					const hit = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
					return el.contains(hit) ? '' : 'covered by ' + (hit?.id || hit?.className || hit?.tagName);
				}",
				$selectors[$name] ?? throw new RuntimeException("no selector for \"$name\""),
			);
			if ($off !== '') {
				throw new RuntimeException("the $name is not on screen: $off");
			}
		}
		$stacked = $this->page->evaluate(
			// language=JavaScript
			"(selectors) => {
				const els = Object.entries(selectors).map(([name, s]) => [name, document.querySelector(s)]);
				const clash = [];
				for (const [i, [a, ea]] of els.entries()) {
					for (const [b, eb] of els.slice(i + 1)) {
						const ra = ea.getBoundingClientRect();
						const rb = eb.getBoundingClientRect();
						if (!ea.contains(eb) && !eb.contains(ea) && ra.left < rb.right && ra.right > rb.left && ra.top < rb.bottom && ra.bottom > rb.top) {
							clash.push(a + ' on ' + b);
						}
					}
				}
				return clash;
			}",
			// The sidebar is the band the toggle and the gear sit on, not a control beside them.
			array_diff_key(array_intersect_key($selectors, array_flip($names)), ['sidebar' => true]),
		);
		if ($stacked !== []) {
			throw new RuntimeException('controls drawn on one another: ' . implode(', ', $stacked));
		}
	}

	#[When('I open the sidebar\'s drawer')]
	public function openDrawer(): void
	{
		$this->page->locator(
			// language=JQuery-CSS
			'#menuopen button',
		)->click();
	}

	#[Then('the drawer is filled, not see-through')]
	public function drawerIsFilled(): void
	{
		$background = $this->page->evaluate(
			// language=JavaScript
			"() => getComputedStyle(document.querySelector('#foot')).backgroundColor",
		);
		if (in_array($background, ['transparent', 'rgba(0, 0, 0, 0)'], true)) {
			throw new RuntimeException("the drawer has no fill: $background");
		}
	}

	#[Then('the sidebar is hidden')]
	public function sidebarIsHidden(): void
	{
		$this->page->waitForFunction(
			// language=JavaScript
			"() => document.body.classList.contains('ad-sidebar-hidden') && getComputedStyle(document.querySelector('#foot')).visibility === 'hidden' && document.querySelector('#content').getBoundingClientRect().left < 2",
		);
	}

	#[Then('the sidebar shows')]
	public function sidebarShows(): void
	{
		$this->page->waitForFunction(
			// language=JavaScript
			"() => !document.body.classList.contains('ad-sidebar-hidden') && getComputedStyle(document.querySelector('#foot')).visibility === 'visible' && document.querySelector('#content').getBoundingClientRect().left > 100",
		);
	}

	#[Then('the toggle sits after the traffic lights, and the path after the toggle')]
	public function toggleAfterLights(): void
	{
		$at = (array) $this->page->evaluate(
			// language=JavaScript
			"() => {
				const toggle = document.querySelector('#ad-sidebar-toggle').getBoundingClientRect();
				const path = document.querySelector('#breadcrumb > :not(#menuopen)').getBoundingClientRect();
				return { toggle: toggle.left, toggleEnd: toggle.right, path: path.left };
			}",
		);
		// The lights end 78px in (--ad-lights-end, tokens.css).
		if ($at['toggle'] < 78 + 8 || $at['path'] < $at['toggleEnd'] + 8) {
			throw new RuntimeException('the toggle or the path crowds what is before it: ' . json_encode($at));
		}
	}

	/** No control or text of the page within the toggle's box, and the toggle the one hit at its
	 * centre — a sticky header or the gear drawn over it would take the click. */
	#[Then('the sidebar\'s toggle overlaps nothing')]
	public function toggleOverlapsNothing(): void
	{
		$overlap = $this->page->evaluate(
			// language=JavaScript
			"() => {
				const toggle = document.querySelector('#ad-sidebar-toggle');
				const t = toggle.getBoundingClientRect();
				const hit = document.elementFromPoint(t.left + t.width / 2, t.top + t.height / 2);
				if (!toggle.contains(hit)) {
					return ['covered by ' + (hit?.id || hit?.className || hit?.tagName)];
				}
				const hasText = (el) => [...el.childNodes].some((n) => n.nodeType === Node.TEXT_NODE && n.textContent.trim());
				return [...document.querySelectorAll('body *')]
					.filter((el) => !toggle.contains(el) && !el.contains(toggle))
					.filter((el) => el.matches('a, button, input, select, textarea, img, svg') || hasText(el))
					.filter((el) => el.checkVisibility({ visibilityProperty: true, opacityProperty: true }))
					.filter((el) => {
						const r = el.getBoundingClientRect();
						return r.width > 0 && r.height > 0 && r.left < t.right && r.right > t.left && r.top < t.bottom && r.bottom > t.top;
					})
					.map((el) => el.id || el.className || el.tagName);
			}",
		);
		if ($overlap !== []) {
			throw new RuntimeException('the sidebar\'s toggle overlaps: ' . json_encode($overlap));
		}
	}

	#[When('I press the mouse on the empty toolbar')]
	public function pressEmptyToolbar(): void
	{
		$box = (array) $this->page->evaluate(
			// language=JavaScript
			// Halfway between where the path ends and the toolbar's controls begin: empty at any width.
			"() => {
				const row = document.querySelector('#breadcrumb').getBoundingClientRect();
				const path = document.querySelector('#breadcrumb .ad-breadcrumb-current').getBoundingClientRect();
				const bar = document.querySelector('#breadcrumb .ad-toolbar').getBoundingClientRect();
				return { x: (path.right + bar.left) / 2, y: row.top + row.height / 2 };
			}",
		);
		$this->page->mouse()->click((float) $box['x'], (float) $box['y']);
	}

	#[When('I open the toolbar\'s sort menu')]
	public function openSortMenu(): void
	{
		$this->page->locator(
			// language=JQuery-CSS
			'#breadcrumb .ad-toolbar',
		)->getByRole('button', ['name' => 'Sort'])->click();
		$this->page->waitForFunction(
			// language=JavaScript
			"() => document.querySelector('#ad-sort-menu')?.matches(':popover-open')",
		);
	}

	#[Then('the sort menu offers :items, with :checked checked')]
	public function sortMenuOffers(string $items, string $checked): void
	{
		$menu = (array) $this->page->evaluate(
			// language=JavaScript
			"() => ({
				items: [...document.querySelectorAll('#ad-sort-menu [role=menuitemcheckbox]')].map((el) => el.textContent.trim()),
				checked: [...document.querySelectorAll('#ad-sort-menu [aria-checked=true]')].map((el) => el.textContent.trim()),
				boxHidden: getComputedStyle(document.querySelector('#form select[name=\"order[0]\"]').closest('fieldset')).display === 'none',
			})",
		);
		$want = array_map('trim', explode(',', $items));
		$on = array_map('trim', explode(',', $checked));
		if ($menu['items'] !== $want || $menu['checked'] !== $on || !$menu['boxHidden']) {
			throw new RuntimeException('the sort menu is otherwise: ' . json_encode($menu));
		}
	}

	/** A pick submits adminer's form, so it is done once another page is in. */
	#[When('I pick :item from the sort menu')]
	public function pickFromSortMenu(string $item): void
	{
		$before = $this->page->url();
		$this->page->locator(
			// language=JQuery-CSS
			'#ad-sort-menu',
		)->getByRole('menuitemcheckbox', ['name' => $item, 'exact' => true])->click();
		for ($i = 0; $i < 100 && $this->page->url() === $before; $i++) {
			usleep(100_000);
		}
		$this->page->waitForLoadState('load');
	}

	#[Then('the toolbar\'s title reads :title')]
	public function toolbarTitleReads(string $title): void
	{
		$text = $this->page->evaluate(
			// language=JavaScript
			"() => document.querySelector('#breadcrumb > .ad-breadcrumb-current')?.textContent.trim()",
		);
		if ($text !== $title) {
			throw new RuntimeException("the toolbar's title reads \"$text\", not \"$title\"");
		}
	}

	#[When('I press the mouse on one of the toolbar\'s buttons')]
	public function pressToolbarButton(): void
	{
		$this->page->locator(
			// language=JQuery-CSS
			'#breadcrumb .ad-toolbar-actions a',
		)->first()->dispatchEvent('mousedown');
	}

	/** The sidebar is a drawer there, so nothing holds the bar off the edge. */
	#[Then('the path bar starts at the window\'s edge')]
	public function pathBarAtEdge(): void
	{
		$left = $this->page->evaluate(
			// language=JavaScript
			"() => document.querySelector('#ad-pathbar').getBoundingClientRect().left",
		);
		if ($left > 1) {
			throw new RuntimeException("the path bar starts {$left}px in");
		}
	}

	/** Finder's path bar, along the window's foot: the steps there, each a link but the last, and
	 * the content panel ending where the bar begins, so nothing of the page is under it. */
	#[Then('the path bar reads :path, its steps leading back')]
	public function pathBarReads(string $path): void
	{
		$bar = (array) $this->page->evaluate(
			// language=JavaScript
			"() => {
				const bar = document.querySelector('#ad-pathbar');
				const steps = [...bar.querySelectorAll('.ad-pathbar-step')].map((el) => el.textContent.trim());
				return {
					steps,
					links: bar.querySelectorAll('a').length,
					here: bar.querySelector('[aria-current=page]')?.textContent.trim(),
					barTop: bar.getBoundingClientRect().top,
					contentBottom: document.querySelector('#content').getBoundingClientRect().bottom,
				};
			}",
		);
		$want = array_map('trim', explode('›', $path));
		if ($bar['steps'] !== $want || $bar['here'] !== end($want) || $bar['links'] < count($want) - 1) {
			throw new RuntimeException('the path bar reads otherwise: ' . json_encode($bar));
		}
		if ($bar['contentBottom'] > $bar['barTop'] + 1) {
			throw new RuntimeException('the content runs under the path bar: ' . json_encode($bar));
		}
	}

	#[Then('/^the window was asked to move (\d+) times?$/')]
	public function windowAskedToMove(string $times): void
	{
		$drags = $this->page->evaluate(
			// language=JavaScript
			'() => window.adDrags',
		);
		if ((int) $drags !== (int) $times) {
			throw new RuntimeException("asked to move $drags times, not $times");
		}
	}

	#[Then('the toolbar\'s controls fit beside the path')]
	public function toolbarFits(): void
	{
		$fit = (array) $this->page->evaluate(
			// language=JavaScript
			"() => {
				const row = document.querySelector('#breadcrumb').getBoundingClientRect();
				const bar = document.querySelector('#breadcrumb .ad-toolbar').getBoundingClientRect();
				const path = document.querySelector('#breadcrumb .ad-breadcrumb-current').getBoundingClientRect();
				return { rowRight: row.right, barRight: bar.right, barLeft: bar.left, pathRight: path.right, pathWidth: path.width, parts: [...document.querySelectorAll('#breadcrumb .ad-toolbar > *, #breadcrumb .ad-toolbar-actions > a')].map((el) => el.className + ':' + Math.round(el.getBoundingClientRect().width) + ':' + getComputedStyle(el).display) };
			}",
		);
		// Inside the row, clear of where you are, and that still readable rather than squeezed out.
		if ($fit['barRight'] > $fit['rowRight'] + 1 || $fit['barLeft'] < $fit['pathRight'] || $fit['pathWidth'] < 24) {
			throw new RuntimeException('the toolbar runs out of its row: ' . json_encode($fit));
		}
	}

	// ── accessibility ────────────────────────────────────────────────────────────────────────

	/** axe-core (a dev dependency in package.json) against what is on screen, at WCAG 2.2 AA.
	 * Evaluated rather than added as a <script>: adminer's CSP carries a nonce, which turns
	 * 'unsafe-inline' off, and evaluate() is not a script the page loads. Scoped to what is ours —
	 * the sidebar, the toolbar, our dialogs and the login — because adminer's own markup (its
	 * tables, its forms) is upstream's, and a violation there is a pull request to adminer, not a
	 * failure here — so is a plugin's from adminer's catalogue inside our sidebar, the table
	 * filter's unlabelled field. What axe finds there is printed, so it is seen.
	 */
	#[Then('axe finds no violations in our own chrome')]
	public function axeFindsNothingInOurs(): void
	{
		$found = $this->axe();
		$ours = array_filter($found, fn(array $v) => $v['ours']);
		$theirs = array_filter($found, fn(array $v) => !$v['ours']);
		if ($theirs) {
			fwrite(STDERR, "axe, in adminer's markup (not failed): " . json_encode(array_values($theirs), JSON_UNESCAPED_SLASHES) . "\n");
		}
		if ($ours) {
			throw new RuntimeException('axe: ' . json_encode(array_values($ours), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
		}
	}

	/** @return list<array{id: string, impact: string, help: string, ours: bool, nodes: list<string>}> */
	private function axe(): array
	{
		$source = (string) file_get_contents(dirname(__DIR__, 3) . '/node_modules/axe-core/axe.min.js');
		$this->page->evaluate($source);
		// Settled first: a fade mid-way reads as low contrast.
		$this->page->evaluate(
			// language=JavaScript
			'() => new Promise((done) => setTimeout(done, 400))',
		);
		return (array) $this->page->evaluate(
			// language=JavaScript
			"async () => {
				const upstream = '#filter-field';
				const ours = '#foot, #breadcrumb, #ad-sidebar-toggle, #ad-sidebar-resizer, #desktop-gear, dialog, .ad-toolbar, .ad-login, .ad-connections, .ad-versions, #table td > a.edit';
				const result = await axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'] } });
				const out = [];
				for (const v of result.violations) {
					const split = { true: [], false: [] };
					for (const node of v.nodes) {
						const el = document.querySelector(node.target[0]);
						split[!!el?.closest(ours) && !el.matches(upstream)].push(node.target.join(' ') + (node.any[0]?.message ? ' — ' + node.any[0].message : ''));
					}
					for (const side of [true, false]) {
						if (split[side].length) {
							out.push({ id: v.id, impact: v.impact, help: v.help, ours: side, nodes: split[side].slice(0, 5) });
						}
					}
				}
				return out;
			}",
		);
	}

	// ── a long select, filtered ──────────────────────────────────────────────────────────────

	/** A click, as a mouse would: select-filter.js takes the mousedown the native menu opens on. */
	#[When('I open the schema switch')]
	public function openSchemaSwitch(): void
	{
		$this->page->locator(
			// language=JQuery-CSS
			'#dbs select[name=ns]',
		)->click();
		$this->page->locator(
			// language=JQuery-CSS
			'.ad-select-filter input',
		)->waitFor();
	}

	#[Then('the :schema schema is open')]
	public function schemaIsOpen(string $schema): void
	{
		$this->page->waitForURL("**ns=$schema**");
	}

	/** From the keyboard: the first letter on the focused select opens the filter, the rest land in
	 * its field. */
	#[When('I type :text into the language switch')]
	public function typeIntoLanguageSwitch(string $text): void
	{
		$this->page->locator(
			// language=JQuery-CSS
			'#desktop-lang-slot select',
		)->focus();
		$this->page->keyboard()->type($text);
	}

	#[Then('the filter is closed, the settings dialog still open and the language still :lang')]
	public function filterClosedInSettings(string $lang): void
	{
		$state = (string) $this->page->evaluate(
			// language=JavaScript
			'() => document.querySelector(".ad-select-filter") ? "the filter is still open"
				: !document.querySelector("#desktop-settings").open ? "the settings dialog closed"
				: document.querySelector("#desktop-lang-slot select").value',
		);
		if ($state !== $lang) {
			throw new RuntimeException("after Escape: $state");
		}
	}

	/** The reload shortcut in the notation of the machine the app runs on — the fixture serves it
	 * from this one, so PHP_OS_FAMILY here is the app's too. */
	#[Then('it lists reload in this platform\'s notation')]
	public function reloadInPlatformNotation(): void
	{
		$expected = PHP_OS_FAMILY === 'Darwin' ? '⌘R' : 'Ctrl+R';
		$keys = (array) $this->page->evaluate(
			// language=JavaScript
			"() => [...document.querySelectorAll('#desktop-panel-shortcuts kbd')].map((k) => k.textContent.trim())",
		);
		if (!in_array($expected, $keys, true)) {
			throw new RuntimeException("no $expected among the shortcuts: " . implode(', ', $keys));
		}
	}

	/** The width of every field the user can see, and the grip of the first — its bottom-right
	 * corner, where the browser puts the resize handle. The hidden textarea behind a JUSH <pre> is
	 * skipped; it has no width to speak of.
	 *
	 * @return array<string, mixed>
	 */
	private function fields(): array
	{
		return (array) $this->page->evaluate(
			// language=JavaScript
			"() => {
				const all = [...document.querySelectorAll('#form > table.layout textarea, #form > table.layout pre.jush')]
					.filter((el) => el.offsetWidth > 0);
				if (!all.length) { return { widths: [], grip: null }; }
				const r = all[0].getBoundingClientRect();
				return {
					widths: all.map((el) => el.getBoundingClientRect().width),
					grip: { x: r.right - 3, y: r.bottom - 3 },
				};
			}",
		);
	}
}
