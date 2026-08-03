Feature: The url the app records for an agent to come back to
  Recording adminer.php instead of the connected URL cost a round of debugging: Adminer takes the
  server and the database from the query string rather than from the session, so the agent's
  request reached an adminer with no driver at all and every tool call died on a null. That is a
  string-building mistake, and it deserves a scenario that needs no database to catch it.

  Scenario: The connection survives into the recorded url
    When the app records where it is, served from "127.0.0.1:18080" at "/adminer.php"
    Then the url recorded while connected is "http://127.0.0.1:18080/<me>"

  Scenario: Nothing is recorded when there is nothing worth borrowing
    When the app records where it is, served from "127.0.0.1:18080" at "/adminer.php"
    Then nothing is recorded while disconnected

  Scenario: Nothing is recorded without a host to build an absolute url from
    When the app records where it is, served from "nowhere" at "/adminer.php"
    Then nothing is recorded at all

  Scenario: A subdirectory is kept, and not doubled
    When the app records where it is, served from "h" at "/tools/adminer.php"
    Then the url recorded while connected is "http://h/tools/<me>"

  Scenario: Windows hands back backslashes, which are not url separators
    When the app records where it is, served from "h" at "\tools\adminer.php"
    Then the url recorded while connected is "http://h/tools/<me>"

  Scenario: A request on a stale handshake is answered in JSON, saying what to do
    Then a request on a stale handshake is told to log in again
