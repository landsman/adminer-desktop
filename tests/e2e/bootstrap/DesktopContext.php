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
 * Which driver an instance is for comes from the Behat suite it belongs to, see behat.yml.
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
		$this->page->context()->close();
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

	/** The scheme the OS is pretending to be is a browser-context option, so this opens a new one —
	 * which is why it comes before logging in rather than after.
	 */
	#[Given('the browser is in the :scheme scheme')]
	public function browserIsInScheme(string $scheme): void
	{
		$this->page->context()->close();
		$this->page = $this->newPage(['colorScheme' => $scheme]);
	}

	#[Given('I am logged in')]
	public function logIn(): void
	{
		e2e_login($this->page, $this->fix);
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

	#[When('I open the edit form for :table row :id')]
	public function openEditForm(string $table, string $id): void
	{
		$this->go(['edit' => $table, 'where[id]' => $id]);
	}

	/** The import page is the sql page in import mode: `import=` is what flips it (adminer.php sets
	 * $_GET["sql"] from it), and that is the page carrying the sql_file[] upload.
	 */
	#[When('I open the import page')]
	public function openImportPage(): void
	{
		$this->go(['import' => '']);
	}

	#[When('I reload the page')]
	public function reload(): void
	{
		$this->goto($this->page->url());
	}

	// ── the data list ────────────────────────────────────────────────────────────────────────

	/** Adminer's heading link, addressed by the column it sorts rather than by its position: the
	 * span beside it holds the search and descending links, and the count differs per driver.
	 */
	#[When('I sort by the :column column')]
	public function sortBy(string $column): void
	{
		$this->before = $this->list();
		$this->page->evaluate("() => document.querySelector('[id=\"th[$column]\"] a').click()");
		$this->after = $this->settled($this->before, fn (): array => $this->list());
	}

	#[When('I step to the next page')]
	public function stepToNextPage(): void
	{
		$this->before = $this->list() + $this->pager();
		// The first control that is a link: on page one, first and previous are spans.
		$this->page->evaluate("() => [...document.querySelectorAll('a.ad-page-step')][0].click()");
		$this->after = $this->settled($this->before, fn (): array => $this->list() + $this->pager());
	}

	#[When('I pick page :number from the list')]
	public function pickPage(string $number): void
	{
		$this->before = $this->list() + $this->pager();
		// By value, not by label: the option labelled 10 is page 9, and a bare string would match
		// the label. Adminer numbers pages from zero.
		$value = (int) $number - 1;
		$this->page->evaluate("() => {
			const list = document.querySelector('.ad-page-select');
			list.value = '$value';
			list.dispatchEvent(new Event('change'));
		}");
		$this->after = $this->settled($this->before, fn (): array => $this->list() + $this->pager());
	}

	#[When('I pick :limit rows a page')]
	public function pickPageSize(string $limit): void
	{
		$this->page->locator("#form select[name='limit']")->selectOption($limit);
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

	#[Then('the count beside it reads :rows rows')]
	public function countReads(string $rows): void
	{
		$pager = $this->pager();
		if (!str_contains((string) $pager['total'], $rows)) {
			throw new RuntimeException("the count reads '{$pager['total']}', which is not the $rows rows");
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
		$handle = $this->page->evaluate(/** @lang JavaScript */ "() => {
			const h = document.querySelector('#ad-sidebar-resizer');
			if (!h) { return null; }
			const r = h.getBoundingClientRect();
			return { x: r.x + r.width / 2, y: r.y + r.height / 2 };
		}");
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
		$this->page->locator('#ad-sidebar-resizer')->focus();
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
		$width = (float) $cold->evaluate($what === 'sidebar'
			? "() => document.querySelector('#foot').getBoundingClientRect().width"
			: "() => document.querySelector('#form > table.layout textarea').getBoundingClientRect().width");
		$cold->close();
		if ($width <= 0) {
			throw new RuntimeException("nothing to measure on the fresh page — is it the $what page at all?");
		}
		if (abs($width - $stored) > 12) {
			throw new RuntimeException(sprintf('a fresh page opened at %.0f, not the stored %d', $width, $stored));
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
		$isDark = (bool) $this->page->evaluate("() => matchMedia('(prefers-color-scheme: dark)').matches");
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
		$moved = $this->page->evaluate(/** @lang JavaScript */ "() => {
			const menu = document.querySelector('#menu'), gear = document.querySelector('#desktop-gear');
			const top = gear.getBoundingClientRect().top;
			menu.scrollTop = 200;
			return top - gear.getBoundingClientRect().top;
		}");
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
		if (!$this->page->evaluate("() => document.querySelector('#desktop-settings').open")) {
			$this->page->locator('#desktop-gear')->click();
			usleep(300_000); // showModal() animates, and its contents are display:none until it lands
		}
	}

	#[When('I pick the :density row density')]
	public function pickDensity(string $density): void
	{
		$this->page->locator("input[name=\"density\"][value=\"$density\"]")->check(['force' => true]);
	}

	#[When('I force the :appearance appearance')]
	public function forceAppearance(string $appearance): void
	{
		$this->page->locator("input[name=\"appearance\"][value=\"$appearance\"]")->check(['force' => true]);
	}

	/** Whichever gallery design is offered first, so this does not break when the catalogue changes. */
	#[When('I pick the first gallery design')]
	public function pickFirstDesign(): void
	{
		$design = $this->page->evaluate(
			'() => { const r = [...document.querySelectorAll("input[name=design_light]")].find((x) => x.value); return r ? r.value : null; }',
		);
		if (!is_string($design) || $design === '') {
			throw new RuntimeException('no gallery design was offered to pick');
		}
		$this->dragged = $design; // reused as "what the last step picked"
		$this->page->locator("input[name=\"design_light\"][value=\"$design\"]")->check(['force' => true]);
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
		$this->page->locator('#desktop-save')->click();
		$this->page->waitForLoadState('networkidle');
	}

	/** The language <select> posts and reloads on its own onchange, without Playwright starting the
	 * click that would wait for it — so poll <html lang> until the reload lands rather than racing
	 * it with one evaluate.
	 */
	#[When('I switch the language to :lang')]
	public function switchLanguage(string $lang): void
	{
		$this->page->locator('#desktop-lang-slot select')->selectOption($lang);
		for ($i = 0; $i < 30; $i++) {
			usleep(200_000);
			try {
				if ((string) $this->page->evaluate('() => document.documentElement.lang') === $lang) {
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
		$this->page->evaluate('() => { window.confirm = () => true; }');
		$this->page->locator('#desktop-reset')->click();
		$this->page->waitForLoadState('networkidle');
		usleep(300_000); // the redirect lands before the unlink is visible to this process
	}

	/** A dragged width goes in through the api the page's own scripts post to: the reset has to
	 * forget what that stored too, not only the dialog's own fields.
	 */
	#[When('a dragged sidebar width has been stored')]
	public function storeADraggedWidth(): void
	{
		$this->page->evaluate(
			"() => navigator.sendBeacon(window.desktopApi.resize, new URLSearchParams({what: 'sidebar', width: '420'}))",
		);
		for ($i = 0; $i < 30 && !isset(e2e_settings($this->fix)['user_resized_px']); $i++) {
			usleep(100_000);
		}
	}

	#[Then('the body carries the :class class')]
	public function bodyCarriesClass(string $class): void
	{
		$body = (string) $this->page->evaluate('() => document.body.className');
		if (!str_contains($body, $class)) {
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
		$osDark = (bool) $this->page->evaluate("() => matchMedia('(prefers-color-scheme: dark)').matches");
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
		$html = (string) $this->page->evaluate('() => document.documentElement.lang');
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
		if ($this->page->evaluate("() => document.getElementById('ad-import-drop') === null") !== true) {
			throw new RuntimeException('the dropzone overlay leaked onto a page that is not the import page');
		}
	}

	#[Then('the dropzone is ready and out of the way')]
	public function dropzoneReady(): void
	{
		$ready = (array) $this->page->evaluate(/** @lang JavaScript */ "() => {
			const input = document.querySelector('input[type=\"file\"][name=\"sql_file[]\"]');
			const overlay = document.getElementById('ad-import-drop');
			const meta = document.querySelector('meta[name=\"ad-import-drop\"]');
			return {
				input: !!input,
				overlay: !!overlay,
				hidden: overlay ? getComputedStyle(overlay).visibility === 'hidden' : null,
				label: overlay ? overlay.textContent : null,
				metaLabel: meta ? meta.content : null,
			};
		}");
		if ($ready['input'] !== true) {
			throw new RuntimeException("Adminer's sql_file[] upload input was not found on the import page");
		}
		if ($ready['overlay'] !== true) {
			throw new RuntimeException('the dropzone overlay was not created on the import page');
		}
		if ($ready['hidden'] !== true) {
			throw new RuntimeException('the dropzone overlay was visible before any drag');
		}
		// The label is rendered server-side and localized: it has to come from the meta
		// AdminerDesktop emits, not from a hardcoded string in the script.
		if (($ready['metaLabel'] ?? '') === '' || $ready['label'] !== $ready['metaLabel']) {
			throw new RuntimeException('the dropzone label did not come from the server meta: '
				. json_encode($ready['label']) . ' against ' . json_encode($ready['metaLabel']));
		}
	}

	/** The same DataTransfer is kept on the window, so the drop below is the tail of one
	 * continuous drag.
	 */
	#[When('I drag a file over the window')]
	public function dragFileOverWindow(): void
	{
		$raised = $this->page->evaluate(/** @lang JavaScript */ "() => {
			const dt = new DataTransfer();
			dt.items.add(new File(['SELECT 1;\\n'], 'dropped-dump.sql', { type: 'application/sql' }));
			window.__adDrag = dt;
			const fire = (t) =>
				window.dispatchEvent(new DragEvent(t, { bubbles: true, cancelable: true, dataTransfer: dt }));
			fire('dragenter');
			fire('dragover');
			const o = document.getElementById('ad-import-drop');
			return o.classList.contains('dragging') && getComputedStyle(o).visibility === 'visible';
		}");
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
		$this->after = (array) $this->page->evaluate(/** @lang JavaScript */ "() => {
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
		}");
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

	/** The tag alone would say nothing — Adminer renders a text column as a textarea anyway. The
	 * plugin's marker is the jush-js class on the one it builds.
	 */
	#[Then("the :field field is the plugin's own editor")]
	public function fieldIsPluginEditor(string $field): void
	{
		$marker = $this->fieldMarker($field);
		if ($marker !== 'TEXTAREA.jush-js') {
			throw new RuntimeException("$field is a $marker — the plugin is not applying");
		}
	}

	#[Then("the :field field is left as Adminer's own")]
	public function fieldIsAdminersOwn(string $field): void
	{
		$marker = $this->fieldMarker($field);
		if ($marker === 'TEXTAREA.jush-js') {
			throw new RuntimeException("$field was taken over by the plugin, and it is not JSON");
		}
	}

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
		if (!str_contains($value, 'Dvořáková')) {
			throw new RuntimeException("$field lost its unicode (JSON_UNESCAPED_UNICODE)");
		}
	}

	#[Then('the :field field lists the keys :keys')]
	public function fieldListsKeys(string $field, string $keys): void
	{
		$listed = (string) $this->page->evaluate($this->fieldScript(
			$field,
			'[...td.querySelectorAll("table > tbody > tr > th")].map((th) => th.textContent).sort().join(",")',
		));
		foreach (explode(',', $keys) as $key) {
			if (!str_contains($listed, trim($key))) {
				throw new RuntimeException("$field has no $key in its table, only " . var_export($listed, true));
			}
		}
	}

	#[Then('the :field field got no table of keys')]
	public function fieldGotNoTable(string $field): void
	{
		if ($this->page->evaluate($this->fieldScript($field, '!!td.querySelector("table")')) === 'true') {
			throw new RuntimeException("$field got a table — the plugin took over a value that is not JSON");
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
	private function newPage(array $options = []): PageInterface
	{
		$context = e2e_browser()->newContext($options + ['viewport' => ['width' => 1600, 'height' => 900]]);
		$context->addCookies([[
			'name' => 'adminer_version',
			'value' => '0',
			'domain' => '127.0.0.1',
			'path' => '/',
		]]);
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
		$this->page->evaluate('() => { window.__adSameDocument = true; }');
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

	/** Evaluate an expression with `el` bound to a field's editor and `td` to the cell around it. */
	private function fieldScript(string $field, string $expression): string
	{
		return "() => {
			const el = document.querySelector('form [name=\"fields[$field]\"]');
			if (!el) { return 'MISSING'; }
			const td = el.closest('td');
			return String($expression);
		}";
	}

	private function fieldTag(string $field): string
	{
		return (string) $this->page->evaluate($this->fieldScript($field, 'el.tagName'));
	}

	private function fieldMarker(string $field): string
	{
		$marker = (string) $this->page->evaluate(
			$this->fieldScript($field, "el.tagName + (el.classList.contains('jush-js') ? '.jush-js' : '')"),
		);
		if ($marker === 'MISSING') {
			throw new RuntimeException("$field is not on the edit form at all");
		}
		return $marker;
	}

	/** Whether a real surface — not a token, a surface — resolved to the dark side. */
	private function surfaceIsDark(): bool
	{
		return (bool) $this->page->evaluate(/** @lang JavaScript */ "() => {
			const el = document.querySelector('#content') || document.body;
			const [r, g, b] = getComputedStyle(el).backgroundColor.match(/\\d+/g).map(Number);
			return r + g + b < 200;
		}");
	}

	private function sidebarWidth(): float
	{
		return (float) $this->page->evaluate("() => document.querySelector('#foot').getBoundingClientRect().width");
	}

	/** @return array<string, mixed> */
	private function list(): array
	{
		return (array) $this->page->evaluate(/** @lang JavaScript */ "() => ({
			first: (document.querySelector('#table tbody tr td:nth-child(3)')?.textContent ?? '').trim(),
			rows: document.querySelectorAll('#table tbody tr').length,
			highlighted: document.querySelectorAll('#table tbody code span[class^=jush]').length,
			sameDocument: window.__adSameDocument === true,
		})");
	}

	/** @return array<string, mixed> */
	private function pager(): array
	{
		return (array) $this->page->evaluate(/** @lang JavaScript */ "() => {
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
		}");
	}

	/** @return array<string, mixed> */
	private function limit(): array
	{
		return (array) $this->page->evaluate(/** @lang JavaScript */ "() => {
			const field = document.querySelector(\"#form [name='limit']\");
			return {
				tag: field ? field.tagName : 'none',
				value: field ? field.value : '',
				options: field && field.options ? [...field.options].map((o) => o.value) : [],
			};
		}");
	}

	/** One column's width and grip, every other column's width, and what the page around it is
	 * doing. Addressed by name, because a position is a different column per driver.
	 *
	 * @return array<string, mixed>
	 */
	private function columns(string $column): array
	{
		return (array) $this->page->evaluate(/** @lang JavaScript */ "(column) => {
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
		}", $column);
	}

	/** The width of every field the user can see, and the grip of the first — its bottom-right
	 * corner, where the browser puts the resize handle. The hidden textarea behind a JUSH <pre> is
	 * skipped; it has no width to speak of.
	 *
	 * @return array<string, mixed>
	 */
	private function fields(): array
	{
		return (array) $this->page->evaluate(/** @lang JavaScript */ "() => {
			const all = [...document.querySelectorAll('#form > table.layout textarea, #form > table.layout pre.jush')]
				.filter((el) => el.offsetWidth > 0);
			if (!all.length) { return { widths: [], grip: null }; }
			const r = all[0].getBoundingClientRect();
			return {
				widths: all.map((el) => el.getBoundingClientRect().width),
				grip: { x: r.right - 3, y: r.bottom - 3 },
			};
		}");
	}
}
