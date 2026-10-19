<?php
declare(strict_types=1);

namespace Desktop\Assets;

/** Splits a shell command line into what copy-field.latte colours: the program, its flags and
* its quoted arguments.
*
* Not a shell parser, and it does not need to be: the only lines it sees are the ones this app
* prints, so a word is a run of non-spaces or a quoted string, and the first word is the program.
* Anything it does not recognise comes back as plain text, which only costs the colour.
*/
class Shell {
	/** @return list<array{string,string}> kind (cmd, flag, str, text) and the text, in order */
	static function tokens(string $line): array {
		preg_match_all('~"[^"]*"|\'[^\']*\'|\s+|\S+~', $line, $matches);
		$tokens = [];
		$first = true;
		foreach ($matches[0] as $word) {
			if (trim($word) === '') {
				$kind = 'text';
			} elseif ($word[0] === '"' || $word[0] === "'") {
				$kind = $first ? 'cmd' : 'str';
				$first = false;
			} elseif ($first) {
				$kind = 'cmd';
				$first = false;
			} else {
				$kind = $word[0] === '-' ? 'flag' : 'text';
			}
			$tokens[] = [$kind, $word];
		}
		return $tokens;
	}
}
