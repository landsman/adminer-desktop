<?php
declare(strict_types=1);

namespace Desktop;

/** Which adminer-desktop this is, and where on GitHub to read about it — from the version the
* launcher was built with (Env::Version, `git describe --tags --always --dirty`).
*
* A tag links to its release; a build past a tag (tag-N-gSHA) to its commit, the one thing that
* names it exactly; anything else — "dev", a bare SHA from a clone with no tags — to the repo.
*/
final readonly class AppVersion {
	const string REPO = 'https://github.com/landsman/adminer-desktop';

	function __construct(public string $describe) {
	}

	/** Null when served without the launcher, which is the only thing that knows. */
	static function current(): ?self {
		$describe = Env::Version->get();
		return $describe ? new self($describe) : null;
	}

	/** What the footer shows: the tag, and the commit when it is past one. */
	function label(): string {
		$describe = (string) preg_replace('~-dirty$~', '', $this->describe);
		if (preg_match('~^(.+)-\d+-g([0-9a-f]+)$~', $describe, $match)) {
			return "$match[1]+$match[2]";
		}
		return $describe;
	}

	function url(): string {
		$describe = (string) preg_replace('~-dirty$~', '', $this->describe);
		if (preg_match('~^.+-\d+-g([0-9a-f]+)$~', $describe, $match)) {
			return self::REPO . "/commit/$match[1]";
		}
		if ($describe !== 'dev' && !preg_match('~^[0-9a-f]{7,40}$~', $describe)) {
			return self::REPO . "/releases/tag/" . rawurlencode($describe);
		}
		return self::REPO;
	}
}
