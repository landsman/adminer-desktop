<?php
declare(strict_types=1);
/** A Claude Code PostToolUse hook: runs dresscode over the PHP file an agent just wrote.
*
* Not nette's php-fixer plugin, which needs a `php` on PATH and starts dresscode through
* PHP_BINARY: there is no `php` here, and that binary is frankenphp.
*
* A rule the file already broke stays broken (--before), and a file dresscode.neon leaves out stays
* as written (--skip-excluded, for all but cli/), so an edit never rewrites adminer's own files.
*/

$input = json_decode((string) file_get_contents("php://stdin"), true);
$file = is_array($input) ? ($input["tool_input"]["file_path"] ?? null) : null;
if (!is_string($file) || pathinfo($file, PATHINFO_EXTENSION) !== "php" || !is_file($file)) {
	exit(0);
}

$file = (string) realpath($file);

// The hook runs from the session's checkout, but an edit in a worktree belongs to that worktree's
// config and dresscode: an installation outside the config's root finds nothing, and exits 0.
$root = preg_match('~^(.*/\.claude/worktrees/[^/]+)/~', $file, $match) === 1 ? $match[1] : dirname(__DIR__);
if (!is_file($root . "/app/vendor/bin/dresscode")) {
	tellAgent("dresscode did not run on $file: $root has no app/vendor. Run make qa there.");
	exit(0);
}
$command = [PHP_BINARY, "php-cli", $root . "/app/vendor/bin/dresscode", "fix", $file, "-c", $root . "/app/dresscode.neon", "--format", "agent", "--no-color", "--jobs", "1"];
// cli/ lies outside the config's root, so --skip-excluded would leave every file there unchecked.
if (!str_starts_with($file, $root . "/cli/")) {
	$command[] = "--skip-excluded";
}
$original = $input["tool_response"]["originalFile"] ?? null;
$before = is_string($original) ? tempnam(sys_get_temp_dir(), "dresscode-before-") : false;
if ($before !== false && file_put_contents($before, $original) !== false) {
	array_push($command, "--before", $before);
}

$pipes = [];
$process = proc_open($command, [0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]], $pipes, $root);
if (!is_resource($process)) {
	fwrite(STDERR, "dresscode could not be started, $file was not checked\n");
	exit(2);
}
fclose($pipes[0]);
$stdout = trim((string) stream_get_contents($pipes[1]));
$stderr = trim((string) stream_get_contents($pipes[2]));
$status = proc_close($process);
if ($before !== false) {
	unlink($before);
}

// 0 is clean or fixed, where stdout is what changed. Otherwise 1 leaves something to the agent, on
// stdout, and 2 and 3 are a failure only stderr names. Exit 2 is what hands stderr to the agent.
if ($status !== 0) {
	fwrite(STDERR, ($stdout ?: $stderr ?: "dresscode failed on $file with exit code $status") . "\n");
	exit(2);
}
// Without adminer's files a method overriding Adminer\Plugin has no parent, and its rules report clean.
if (!is_file($root . "/app/adminer.php")) {
	$stdout = trim("$stdout\nThe rules that need adminer's classes did not check $file: $root has no app/adminer.php. Run make fetch there.");
}
if ($stdout !== "") {
	tellAgent($stdout);
}

function tellAgent(string $text): void {
	echo json_encode(["hookSpecificOutput" => ["hookEventName" => "PostToolUse", "additionalContext" => $text]], JSON_INVALID_UTF8_SUBSTITUTE);
}
