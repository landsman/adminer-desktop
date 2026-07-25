<?php
declare(strict_types=1);

// Shared bootstrap for the Nette Tester suites: turn on the composer autoloader (so Desktop\ and
// Tester\ resolve) and Tester's assertion handling and reporting. Every test starts with
//   require dirname(__DIR__) . "/bootstrap.php";
// (dirname depth to taste — the tests sit one folder under tests/).

require dirname(__DIR__) . "/app/vendor/autoload.php";
Tester\Environment::setup();
