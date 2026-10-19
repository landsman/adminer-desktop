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

  Scenario: The handshake points at this window and carries nothing that unlocks a password
    Then a handshake is written, pointing at this window through the gate
    And it carries nothing that unlocks a password
    And it is readable only by its owner
    And the gate refuses a call without the token, and with it opens nothing but the MCP endpoint

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
    And the description of "execute_query" mentions "longer than 2000 characters are cut"
    And the description of "execute_query" mentions "always rolled back"
    And the description of "preview_table_data" mentions "whole_values: true"
    And the endpoint answers 200, not adminer's 413
    When an agent calls "list_tables"
    Then the answer mentions "users"

  Scenario: With no database open, current_connection names the one the queries run in
    Given the window is logged in with no database open
    When an agent calls "current_connection"
    Then the database named is "postgres"
    And the answer mentions "no database open"

  Scenario: A write through the tool is refused as read-only and leaves nothing behind
    Given the same statement lands when it is run straight at the database
    When an agent runs the query "INSERT INTO users (name) VALUES ('mcp-rollback-probe') RETURNING id"
    Then the answer is an error mentioning "read-only transaction"
    And no row named "mcp-rollback-probe" survived

  Scenario: A COMMIT smuggled into the query does not let a write escape
    When an agent runs the query "COMMIT; INSERT INTO users (name) VALUES ('mcp-commit-escape')"
    Then the answer is an error mentioning "multiple commands"
    And no row named "mcp-commit-escape" survived

  Scenario: A SELECT-only login refuses what a superuser gets away with inside a read-only query
    Inside BEGIN READ ONLY a superuser still runs a program on the database server, reads its
    files and the password hashes. The login is the boundary, and this is the login to give.
    Given the window is logged in as the SELECT-only reader instead
    When an agent runs the query "COPY (SELECT 1) TO PROGRAM 'id'"
    Then the database refused it
    When an agent runs the query "SELECT pg_read_file('/etc/passwd')"
    Then the database refused it
    When an agent runs the query "SELECT rolpassword FROM pg_authid"
    Then the database refused it
    When an agent runs the query "SELECT name FROM products"
    Then the answer mentions "Konvice"

  Scenario: A long value is cut on a character and reported, and comes back whole on request
    When an agent runs the query "SELECT body, payload FROM long_values"
    Then the value in "body" is cut to 2000 characters of 5000, and the answer says so
    And the answer says "payload" was cut too
    When an agent runs the query "SELECT body, payload FROM long_values" asking for whole values
    Then the value in "body" comes back whole, 5000 characters
    And the value in "payload" comes back whole as JSON, over 2000 characters

  Scenario: With whole values on in the settings, nothing is cut and the description says so
    Given AI access is on and set to return whole values
    When an agent lists the tools
    Then the description of "execute_query" mentions "Every value is returned whole."
    When an agent runs the query "SELECT body, payload FROM long_values"
    Then the value in "body" comes back whole, 5000 characters

  Scenario: With writes allowed the same statement persists, and says so
    Given AI access is on and allowed to write
    When an agent lists the tools
    Then the description of "execute_query" mentions "is committed"
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
