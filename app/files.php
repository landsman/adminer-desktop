<?php
namespace Desktop;

/** Finding files on disk, without a glob per directory level.
*
* A chain of glob("dir/*.php"), glob("dir/*\/*.php") is one refactor away from silently
* matching nothing, and the failure is quiet: a linter that lints no files still exits 0.
*/
class Files {
	/** Get every file with the given extension under $dir, at any depth, sorted.
	*
	* @param string[] $skip path fragments; a file whose path contains one is left out,
	*                       which is how vendored trees stay out of our own tooling
	* @return string[]
	*/
	static function find(string $dir, string $extension = "php", array $skip = array()): array {
		if (!is_dir($dir)) {
			return array();
		}
		$return = array();
		$files = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
		);
		foreach ($files as $file) {
			if (!$file->isFile() || $file->getExtension() !== $extension) {
				continue;
			}
			$path = str_replace('\\', '/', $file->getPathname());
			foreach ($skip as $fragment) {
				if (strpos($path, $fragment) !== false) {
					continue 2;
				}
			}
			$return[] = $path;
		}
		sort($return);
		return $return;
	}
}
