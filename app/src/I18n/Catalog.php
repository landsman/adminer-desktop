<?php
declare(strict_types=1);

namespace Desktop\I18n;

/** The native-shell strings, one [key => text] map per locale the Locale enum declares.
*
* English is the base: its keys are the canonical set and its text the fallback a translation
* degrades to. Holds the loaded strings and answers what each locale still leaves untranslated;
* Generator turns them into the platform formats and renders the coverage report. The map is
* injected, so a test can construct a deliberate gap; load() is the production path that reads
* the Locale/<value>.php files.
*/
final class Catalog {
	/** @param array<string,array<string,string>> $byLocale locale value => [key => text] */
	public function __construct(private readonly array $byLocale) {
	}

	/** Load the per-language file each Locale case declares. A declared locale with no file fails
	* loudly here — the enum is the authority on what must exist.
	*/
	public static function load(): self {
		$byLocale = [];
		foreach (Locale::cases() as $locale) {
			/** @var array<string,string> $strings */
			$strings = require __DIR__ . "/Locale/{$locale->value}.php";
			$byLocale[$locale->value] = $strings;
		}
		return new self($byLocale);
	}

	/** @return array<string,array<string,string>> locale value => [key => text] */
	public function all(): array {
		return $this->byLocale;
	}

	/** English, the base locale: the canonical keys and the fallback text.
	* @return array<string,string>
	*/
	public function base(): array {
		return $this->byLocale[Locale::En->value];
	}

	/** Base keys each locale leaves untranslated — absent or blank both count. Complete locales
	* are omitted, so an empty result means everything is translated.
	* @return array<string,list<string>> locale value => missing keys
	*/
	public function missing(): array {
		$missing = [];
		foreach ($this->byLocale as $locale => $strings) {
			$gaps = [];
			foreach ($this->base() as $key => $_) {
				if (($strings[$key] ?? "") === "") {
					$gaps[] = $key;
				}
			}
			if ($gaps !== []) {
				$missing[$locale] = $gaps;
			}
		}
		return $missing;
	}

	/** Every declared locale translates every base key — the machine-readable gate for CI. */
	public function complete(): bool {
		return $this->missing() === [];
	}
}
