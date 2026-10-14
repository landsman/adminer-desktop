Feature: The activity indicator while a table or a schema loads
  A big table or a schema full of them takes a moment to come, and a page that sits unchanged
  meanwhile looks stuck. Each way there shows the indicator until the answer is on screen.

  Background:
    Given the settings are at their defaults
    And I am logged in
    When I open the "users" table

  Scenario: Opening a table from the sidebar shows it
    Then opening "orders" from the sidebar shows the activity indicator
    And the "orders" table is open

  Scenario: Paging or sorting shows it until the rows are in
    Then fetching the rows shows the activity indicator until they are in

  Scenario: Switching the schema shows it
    Then switching the schema shows the activity indicator
