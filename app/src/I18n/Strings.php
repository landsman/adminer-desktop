<?php
declare(strict_types=1);

namespace Desktop\I18n;

/** Plugin strings for the code that cannot ask Adminer for them.
*
* AdminerDesktop::t() is the normal way in, and it works because Adminer's Plugin::lang() is
* sitting on a loaded Adminer with a LANG constant. Two callers have neither:
*
* - Mcp\Stdio runs as a bare CLI child an agent spawned. No Adminer, no session, no LANG.
* - Mcp\Server and Mcp\Tools run inside a request, but as plain classes with no plugin to hand;
*   threading one through every constructor to reach six sentences is more wiring than the
*   sentences are worth.
*
* So: the same catalogue, the same base-locale fallback, resolved statically. The locale is
* Adminer's LANG where there is one and $LANG from the environment where there is not — which is
* the same signal the launcher matches on, so the bridge answers in the language the app is in.
*
* What is *not* here is the tool names and their descriptions (Mcp\Server::catalogue()). Those
* are instructions to a model rather than text for a person, clients cache them from the first
* connection, and a tool that is called by a different name per locale is one no shared prompt
* or transcript can refer to. The messages a user will read — what broke, what was rolled
* back — are what this translates.
*/
final class Strings {
	/** @var array<string,array<string,string>>|null locale value => [id => text] */
	private static ?array $byLocale = null;

	/** @param literal-string $id */
	public static function t(string $id): string {
		self::$byLocale ??= Catalog::load(Domain::Plugin)->all();
		$locale = self::locale()->value;
		$text = self::$byLocale[$locale][$id] ?? "";
		return $text !== "" ? $text : (self::$byLocale[Locale::En->value][$id] ?? $id);
	}

	/** Adminer's language if it has one, else the environment's, else English. */
	private static function locale(): Locale {
		$lang = defined('Adminer\LANG') ? (string) constant('Adminer\LANG') : (string) (getenv('LANG') ?: '');
		return Locale::tryFrom(strtolower(substr($lang, 0, 2))) ?? Locale::En;
	}
}
