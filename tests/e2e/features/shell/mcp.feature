Feature: An agent reaching the database this window is logged into
  There is no browser in any of this: what is under test is an HTTP session being borrowed by a
  second process, so the scenarios log in with curl and drive the endpoint exactly as an agent
  would.

  The one that earns its keep is the rollback. execute_query runs inside a transaction that is
  always rolled back, and "always" is a claim about a security boundary: if it ever stops being
  true, an agent that was told it had read-only access can quietly write.

  Background:
    Given AI access is on
    And a window is logged in to the demo database

  Scenario: The handshake points at this window and carries the session to borrow
    Then a handshake is written, pointing at this window
    And it carries the session to borrow
    And it is readable only by its owner

  Scenario: The protocol version is negotiated in both directions
    When an agent initializes
    Then it is answered with a protocol version
    When a client newer than us initializes
    Then it is told our version rather than its own
    When a client at "2024-11-05" initializes
    Then it is met at "2024-11-05"

  Scenario: The tools are offered, and they reach the seeded database
    When an agent lists the tools
    Then the tools offered include "current_connection, list_tables, describe_table, preview_table_data, execute_query"
    When an agent calls "list_tables"
    Then the answer mentions "users"

  Scenario: A write through the tool leaves nothing behind
    Given the same statement lands when it is run straight at the database
    When an agent runs the query "INSERT INTO users (name) VALUES ('mcp-rollback-probe') RETURNING id"
    Then the answer says it was rolled back
    And no row named "mcp-rollback-probe" survived

  Scenario: With writes allowed the same statement persists, and says so
    Given AI access is on and allowed to write
    When an agent runs the query "INSERT INTO users (name) VALUES ('mcp-committed') RETURNING id"
    Then the answer no longer claims a rollback
    And the row named "mcp-committed" is in the database

  Scenario: The bridge carries the same call over stdin and stdout
    When an agent runs "SELECT count(*) AS n FROM users" through the stdio bridge
    Then the bridge answered the handshake
    And it returned the counted row

  Scenario: Turning the feature off retracts the handshake rather than just ignoring it
    When AI access is turned off
    Then the handshake is retracted
