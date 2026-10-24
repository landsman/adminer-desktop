Feature: An agent's statement on MySQL that returns no rows
  On postgres a write with writes off is refused by BEGIN READ ONLY. On MySQL it runs inside a
  transaction that is rolled back, and returns no result set. That has to read as a statement that
  ran and was undone, not as an error, nor as a write that stayed.

  Background:
    Given AI access is on
    And a window is logged in to the demo database

  Scenario: With writes off, a write runs and is rolled back, and says so
    When an agent runs the query "INSERT INTO users (name) VALUES ('mcp-mysql-rolled-back')"
    Then it is answered with 1 affected row, not as an error
    And no row named "mcp-mysql-rolled-back" survived
