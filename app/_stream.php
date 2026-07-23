<?php
// M0 probe. Mimics how adminer streams: echo, ob_flush(), flush(), repeat.
// See adminer/sql.inc.php:132, adminer/dump.inc.php:121, adminer/include/functions.inc.php:667.
// Delete once M0 passes and the launcher embeds the app for real.
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
