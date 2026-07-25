<?php
declare(strict_types=1);

namespace Desktop\I18n;

/** One domain's strings, one [id => text] map per locale the Locale enum declares.
*
* A domain is a directory of per-language files beside this class: "native" (the launcher shell,
* turned into .strings + a C table by Generator) and "plugin" (the PHP UI, fed to Adminer's
* lang() as $translations). English is the base: its IDs are the canonical set and its text the
* fallback a translation degrades to. The map is injected, so a test can construct a deliberate
* gap; load() is the production path that reads the <domain>/<value>.php files.
*/
final class Catalog {
	/** A well-formed ID: lowercase dotted namespaces (topic.name), each segment starting with a
	* letter, [a-z0-9_] after. Rejects empty keys, whitespace, uppercase and other oddities. */
	private const string ID_PATTERN = '~^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$~';

	/** @param array<string,array<string,string>> $byLocale locale value => [id => text] */
	public function __construct(private readonly array $byLocale) {
	}

	/** Load a domain's per-language file for each Locale case. A declared locale with no file
	* fails loudly here — the enum is the authority on what must exist.
	*/
	public static function load(Domain $domain): self {
		$byLocale = [];
		foreach (Locale::cases() as $locale) {
			/** @var array<string,string> $strings */
			$strings = require __DIR__ . "/{$domain->value}/{$locale->value}.php";
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

	/** IDs across all locales that are not well-formed (empty, spaced, uppercase, un-namespaced,
	* or otherwise off the topic.name convention). Empty means every ID is valid; the test and
	* `make i18n-check` gate on it.
	* @return list<string>
	*/
	public function malformedIds(): array {
		$bad = [];
		foreach ($this->byLocale as $strings) {
			foreach (array_keys($strings) as $id) {
				if (preg_match(self::ID_PATTERN, $id) !== 1) {
					$bad[$id] = $id;
				}
			}
		}
		return array_values($bad);
	}
}
