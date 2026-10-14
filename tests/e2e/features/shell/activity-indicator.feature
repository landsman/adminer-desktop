Feature: The activity indicator while a database, a table or a schema loads
  A big table or a schema full of them takes a moment to come, and a page that sits unchanged
  meanwhile looks stuck. Each way there shows the indicator until the answer is on screen.

  Background:
    Given the settings are at their defaults
    And I am logged in
    When I open the "users" table

  Scenario: Opening a table from the sidebar shows it
    Then opening "orders" from the sidebar shows the activity indicator
    And the "orders" table is open

  Scenario: Opening a database from the server's list shows it
    When I open the server's list of databases
    Then following "demo" in the content shows the activity indicator
    And it lands on a URL with "db=demo"

  Scenario: Opening a table from the database's list shows it
    When I open the database
    Then following "orders" in the content shows the activity indicator
    And it lands on a URL with "table=orders"

  Scenario: Select data on a table's structure shows it
    When I open the structure of the "users" table
    Then following "Select data" in the content shows the activity indicator
    And the "users" table is open

  Scenario: An anchor on the same page leaves it down, as no page load would take it away
    When I open the database
    Then following "Routines" in the content leaves the activity indicator down

  Scenario: Paging or sorting shows it until the rows are in
    Then fetching the rows shows the activity indicator until they are in

  Scenario: Switching the schema shows it
    Then switching the schema shows the activity indicator
