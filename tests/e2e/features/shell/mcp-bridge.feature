Feature: The stdio bridge when there is no app behind it
  What the end-to-end scenarios cannot provoke on demand — the app not running, the window closed
  mid-session, an expired login. Each is a message an agent has to be able to act on, and each is
  one line away from being silence or a page of HTML instead. No app and no database here: the
  transport is a closure and the handshake is a temporary directory.

  Scenario: It finds the data dir with no environment of ours
    Then the bridge finds the data dir with no environment of ours

  Scenario: With no handshake the connection still comes up, carrying the reason
    Given the app is not running
    When a client initializes through the bridge
    Then initialize still succeeds
    And the agent is told not to reach the database another way
    When a client lists the tools through the bridge
    Then the tools offered include "list_connections, current_connection, list_databases, list_tables, describe_table, preview_table_data, execute_query"
    When a client calls a tool through the bridge
    Then the call is a tool error saying "AI access"

  Scenario: Described offline as the settings say, since a client keeps the list it got at connect
    Given the app is not running, with writes allowed in its settings
    When a client lists the tools through the bridge
    Then the description of "execute_query" mentions "is committed"

  Scenario: A notification with no handshake is answered with silence
    Given the app is not running
    When a notification arrives through the bridge
    Then nothing is sent back

  Scenario: A closed window is reported as one
    Given the window was closed
    When a client calls a tool through the bridge
    Then the answer says "stopped answering"

  Scenario: An empty answer forwards nothing
    Given the app answers with "nothing"
    When a notification arrives through the bridge
    Then nothing is sent back

  Scenario: HTML back means the session expired, and the agent is told so
    Given the app answers with "a login page"
    When a client calls a tool through the bridge
    Then the answer says "expired"

  Scenario: A JSON answer is forwarded exactly as the server said it
    Given the app answers with "a json answer"
    Then it is forwarded unchanged

  Scenario: It posts where the handshake says, with the session it was given
    When a client asks the bridge for anything
    Then it posts to the connected url with mcp=1, showing the gate its token

  Scenario: It pumps a stream, blank lines and all
    Then the bridge answers each request once and every notification never

  Scenario: MySQL grants that reach the server are told from grants on the data
    Then of MySQL's grants only ALL on *.*, SUPER, FILE and the mysql schema are refused
