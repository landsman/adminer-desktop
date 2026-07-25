<?php
declare(strict_types=1);

namespace Desktop\I18n;

/** Emits the native-shell strings into each platform's own native format, from a Catalog.
*
* macOS reads a Localizable.strings per locale (NSLocalizedString); the Linux/Windows launcher
* #includes a generated C table (launcher.h.tmpl filled in). Both formats share C string-literal
* escaping, so quote() serves both; placeholders are rewritten from NSString's %@ to printf's %s
* for the C side. English is the base and the fallback, so it gets no C rows of its own.
*/
final class Generator {
	private const string BANNER = 'Generated from app/src/I18n/Locale/*.php by cli/i18n.php. Do not edit — edit the language files and run `make i18n`.';

	public function __construct(private readonly Catalog $catalog) {
	}

	/** Write lproj/<locale>.lproj/Localizable.strings and launcher/i18n_gen.h under $root. */
	public function generate(string $root): void {
		foreach ($this->catalog->all() as $locale => $strings) {
			$this->put("$root/lproj/$locale.lproj/Localizable.strings", $this->strings($strings));
		}
		$this->put("$root/launcher/i18n_gen.h", $this->header());
	}

	/** A human coverage report: the per-locale translated / missing / percentage table, then the
	* keys each incomplete locale is missing. Catalog::complete() is the machine-readable gate; this
	* returns a string rather than printing so it can be asserted.
	*/
	public function report(): string {
		$total = count($this->catalog->base());
		$missing = $this->catalog->missing();

		$out = "Native-shell translations (app/src/I18n/Locale/, base " . Locale::En->value . ", $total strings)\n\n";
		$out .= sprintf("  %-8s  %-11s  %-8s  %s\n", "locale", "translated", "missing", "coverage");
		foreach (array_keys($this->catalog->all()) as $locale) {
			$gaps = count($missing[$locale] ?? []);
			$done = $total - $gaps;
			$out .= sprintf("  %-8s  %-11s  %-8d  %d%%\n", $locale, "$done/$total", $gaps, $total > 0 ? (int) round($done / $total * 100) : 100);
		}

		if ($missing === []) {
			return $out . "\nAll translations complete.\n";
		}
		foreach ($missing as $locale => $keys) {
			$out .= "\nMissing in $locale:\n";
			foreach ($keys as $key) {
				$out .= "  - $key\n";
			}
		}
		return $out;
	}

	/** A Localizable.strings body for one locale: every base key, translated or degraded to
	* English. The key is what NSLocalizedString looks up.
	* @param array<string,string> $strings
	*/
	private function strings(array $strings): string {
		$lines = [];
		foreach ($this->catalog->base() as $key => $en) {
			$lines[] = self::quote($key) . " = " . self::quote($strings[$key] ?? $en) . ";";
		}
		return "/* " . self::BANNER . " */\n\n" . implode("\n", $lines) . "\n";
	}

	/** The launcher's C lookup table, filled into launcher.h.tmpl. Only a locale's real
	* translations become rows — English is the fallback — and placeholders become printf's.
	*/
	private function header(): string {
		$rows = [];
		foreach ($this->catalog->all() as $locale => $strings) {
			if ($locale === Locale::En->value) {
				continue;
			}
			foreach ($this->catalog->base() as $key => $en) {
				$tr = $strings[$key] ?? "";
				if ($tr !== "" && $tr !== $en) {
					$rows[] = "\t{" . self::quote($locale) . ", " . self::quote(self::toC($key)) . ", " . self::quote(self::toC($tr)) . "},";
				}
			}
		}
		$template = (string) file_get_contents(__DIR__ . "/launcher.h.tmpl");
		return strtr($template, [
			"{{banner}}" => self::BANNER,
			"{{rows}}" => implode("\n", $rows),
		]);
	}

	/** Write $content to $path, creating its directory first. */
	private function put(string $path, string $content): void {
		$dir = dirname($path);
		if (!is_dir($dir)) {
			mkdir($dir, 0755, true);
		}
		file_put_contents($path, $content);
	}

	/** A C / .strings string literal: the text quoted, with the escapes both formats share. */
	private static function quote(string $s): string {
		return '"' . strtr($s, [
			"\\" => "\\\\",
			"\"" => "\\\"",
			"\n" => "\\n",
			"\t" => "\\t",
			"\r" => "\\r",
		]) . '"';
	}

	/** macOS %@/%1$@ placeholders -> printf %s/%1$s for the C table. */
	private static function toC(string $s): string {
		return (string) preg_replace('/%(\d+\$)?@/', '%${1}s', $s);
	}
}
