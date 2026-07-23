<?php
// M0 probe. Mimics how adminer streams: echo, ob_flush(), flush(), repeat.
// See adminer/sql.inc.php:132, adminer/dump.inc.php:121, adminer/include/functions.inc.php:667.
// Delete once M0 passes and the launcher embeds the app for real.
// Exactly what adminer does at include/bootstrap.inc.php:63, and the probe is worthless
// without it: PHP's own max_execution_time defaults to 30s on the linux build (0 on the
// mac one), so leaving it out measured PHP's limit rather than the transport's, and CI
// duly failed at 8 lines in 30 seconds.
set_time_limit(0);

// ?probe=1 reports what the app actually sees on disk. Lives here rather than in its own
// file because this one is already excluded from every package.
if ($_GET["probe"]) {
	header("Content-Type: text/plain");
	$dir = str_replace('\\', '/', __DIR__);
	$glob = glob("$dir/designs/*/*.css");
	echo "dir=$dir\n";
	echo "designs_is_dir=" . (int) is_dir("$dir/designs") . "\n";
	echo "glob_css=" . (is_array($glob) ? count($glob) : "FALSE") . "\n";
	echo "glob_raw_dir=" . (is_array($g = glob("$dir/designs/*")) ? count($g) : "FALSE") . "\n";
	echo "scandir=" . (is_dir("$dir/designs") ? count(scandir("$dir/designs")) : -1) . "\n";
	echo "first=" . ($glob ? $glob[0] : "-") . "\n";
	exit;
}

$n = (int) ($_GET["n"] ?: 24);
$s = (int) ($_GET["s"] ?: 5);
header("Content-Type: text/plain");
for ($i = 0; $i < $n; $i++) {
	echo $i . "\n";
	if (ob_get_level()) {
		ob_flush();
	}
	flush();
	sleep($s);
}
