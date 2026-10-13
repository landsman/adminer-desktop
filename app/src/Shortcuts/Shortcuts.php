<?php
declare(strict_types=1);

namespace Desktop\Shortcuts;

use Desktop\Latte;
use Desktop\Os;

/** The keyboard shortcuts the app answers to, and the dialog that lists them (? opens it).
*
* One list for every platform, printed in the platform's own notation — ⇧⌘↩ on a mac,
* Ctrl+Shift+Enter elsewhere. A row that only one platform has, such as the mac menu bar's,
* says so, and the others never see it. A shortcut added to the app gets a row here, or nobody
* finds it.
*/
class Shortcuts {
	private \AdminerDesktop $desktop;
	private Os $os;

	function __construct(\AdminerDesktop $desktop, ?Os $os = null) {
		$this->desktop = $desktop;
		$this->os = $os ?? Os::current();
	}

	/** Groups of rows: a translation ID, the combinations that do it, and the platforms it exists
	* on (null for all). Each combination is its keys, a Key or the character printed on the key.
	* @return array<string, list<array{string, list<list<Key|string>>, ?list<Os>}>>
	*/
	private function groups(): array {
		return [
			'shortcuts.group_app' => [
				['shortcuts.help', [['?']], null],
				['shortcuts.reload', [[Key::Mod, 'R'], ['F5']], null],
				['shortcuts.close', [[Key::Escape]], null],
				['shortcuts.adminer', [[Key::Mod, '1']], [Os::Mac]],
				['shortcuts.editor', [[Key::Mod, '2']], [Os::Mac]],
				['shortcuts.logs', [[Key::Mod, 'L']], [Os::Mac]],
				['shortcuts.quit', [[Key::Mod, 'Q']], [Os::Mac]],
			],
			// adminer's own, from its functions.js: submitKeydown() and editingKeydown().
			'shortcuts.group_forms' => [
				['shortcuts.submit', [[Key::Mod, Key::Enter]], null],
				['shortcuts.insert_next', [[Key::Mod, Key::Shift, Key::Enter]], null],
				['shortcuts.field_up', [[Key::Mod, Key::Up]], null],
				['shortcuts.field_down', [[Key::Mod, Key::Down]], null],
			],
		];
	}

	/** The groups as the template prints them: translated, this platform's rows only, each
	* combination already written out.
	* @return array<string, list<array{string, list<string>}>>
	*/
	function rows(): array {
		$rows = [];
		foreach ($this->groups() as $group => $shortcuts) {
			foreach ($shortcuts as [$id, $combos, $only]) {
				if ($only === null || in_array($this->os, $only, true)) {
					$rows[$this->desktop->t($group)][] = [$this->desktop->t($id), array_map(fn($keys) => self::write($keys, $this->os), $combos)];
				}
			}
		}
		return $rows;
	}

	/** One combination in this platform's notation: ⇧⌘↩ run together on a mac, Ctrl+Shift+Enter
	* joined elsewhere.
	* @param list<Key|string> $keys
	*/
	static function write(array $keys, Os $os): string {
		$order = fn(Key|string $key): int => $key instanceof Key ? $key->order($os) : 4;
		usort($keys, fn($a, $b) => $order($a) <=> $order($b));
		$labels = array_map(fn(Key|string $key): string => $key instanceof Key ? $key->label($os) : $key, $keys);
		return implode($os === Os::Mac ? '' : '+', $labels);
	}

	function render(): void {
		Latte::engine()->render(__DIR__ . "/shortcuts-dialog.latte", [
			"desktop" => $this->desktop,
			"rows" => $this->rows(),
		]);
	}
}
