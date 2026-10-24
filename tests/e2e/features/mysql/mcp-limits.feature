Feature: An agent statement on MySQL stops at its limit
  MySQL's max_execution_time is a different setting from postgres' statement_timeout, and it
  covers SELECT only, so the limit is asserted here as well as on postgres. The e2e fixture sets it
  to 3 seconds; a three-way join over information_schema runs far longer.

  Background:
    Given AI access is on
    And a window is logged in to the demo database

  Scenario: A SELECT that outlasts its limit is stopped by MySQL, and the agent told why
    When an agent runs the query "SELECT COUNT(*) FROM information_schema.columns a, information_schema.columns b, information_schema.columns c"
    Then the answer is an error mentioning "ran longer than 3 seconds"
