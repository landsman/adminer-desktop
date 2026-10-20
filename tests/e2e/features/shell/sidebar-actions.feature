Feature: The sidebar's actions are icons
  SQL command, Import, Export and Create table are a row of icons after the database and schema
  pickers, on their row. Each
  keeps adminer's own label as its tooltip and as the name a screen reader reads.

  Scenario: Each action is an icon, named by its label
    Given the settings are at their defaults
    And I am logged in
    When I open the database
    Then the sidebar offers "SQL command, Import, Export, Create table", each an icon
    And each table is marked with an icon, in line with the database name above

  Scenario: The database and schema pickers are not labelled in front of them
    Given the settings are at their defaults
    And I am logged in
    When I open the database
    Then the database and schema pickers fill their row, with no "DB:" or "Schema:" in front of them
    And the actions follow the pickers on their row
    And each picker is as wide as the name in it, and a long name stays inside the sidebar
    When I point at the schema picker
    Then a tooltip says "Schema"

  Scenario: The database list has the same one row
    Given the settings are at their defaults
    And I am logged in
    When I open the server's list of databases
    Then the actions follow the pickers on their row

  Scenario: A label shows as the app's own tooltip, not the system's
    Given the settings are at their defaults
    And I am logged in
    When I open the database
    And I point at "Import" in the sidebar
    Then a tooltip says "Import" right away, and the link keeps its name
