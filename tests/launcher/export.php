<?php

// One page per way adminer starts a download, for downloads.sh: ?case=link is the SQL command's
// JS export (an <a download> on a blob:), ?case=mailto a result cell's mail link, and
// ?case=file|gz|text the server export, answered with dump_headers()'s headers (adminer 6.1.1).
// #go is what the test clicks; "ready" in the page means nothing has replaced it yet.

if ($_POST) {
	$output = $_POST["output"];
	header("Content-Type: " . ($output == "gz" ? "application/x-gzip" : "text/plain; charset=utf-8"));
	if ($output != "text") {
		header("Content-Disposition: attachment; filename=sql.sql" . ($output == "gz" ? ".gz" : ""));
	}
	echo $output == "gz" ? gzencode("SELECT 1;\n") : "SELECT 1;\n";
	exit;
}

$case = $_GET["case"];
echo "<p>ready</p>\n";
if ($case == "link") {
	// adminer's sqlExport() for output "save", bound from a script as adminer's is: in an onclick
	// attribute, URL would be document.URL
	echo "<button id='go'>Export</button>\n<script>\n"
		. "document.getElementById('go').addEventListener('click', () => {\n"
		. "\tconst a = document.createElement('a');\n"
		. "\ta.href = URL.createObjectURL(new Blob(['a,b\\r\\n1,2\\r\\n'], {type: 'text/csv; charset=utf-8'}));\n"
		. "\ta.download = 'sql.csv';\n"
		. "\tdocument.body.append(a);\n"
		. "\ta.click();\n"
		. "\ta.remove();\n"
		. "});\n</script>\n";
} elseif ($case == "mailto") {
	echo "<a id='go' href='mailto:someone@example.com'>someone@example.com</a>\n";
} else {
	echo "<form method='post'><input type='hidden' name='output' value='" . htmlspecialchars($case) . "'>"
		. "<input id='go' type='submit' value='Export'></form>\n";
}
