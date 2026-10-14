Feature: Long comments wrap
  Adminer prints a table's comment and every column's in cells that never wrap, so one long
  comment widened the whole table past the window and hid the end of every row behind a sideways
  scroll. They wrap instead, on the structure page and in the database's list of tables.

  Background:
    Given I am logged in

  Scenario: The structure page wraps the table's and the columns' comments
    When I open the structure of the "orders" table
    Then the page fits inside the content panel

  Scenario: The list of tables wraps a table's comment
    When I open the list of tables
    Then the page fits inside the content panel
