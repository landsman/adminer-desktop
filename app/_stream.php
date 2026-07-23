<?php
// M0 probe. Mimics how adminer streams: echo, ob_flush(), flush(), repeat.
// See adminer/sql.inc.php:132, adminer/dump.inc.php:121, adminer/include/functions.inc.php:667.
// Delete once M0 passes and the launcher embeds the app for real.
// Exactly what adminer does at include/bootstrap.inc.php:63, and the probe is worthless
// without it: PHP's own max_execution_time defaults to 30s on the linux build (0 on the
// mac one), so leaving it out measured PHP's limit rather than the transport's, and CI
// duly failed at 8 lines in 30 seconds.
set_time_limit(0);

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
