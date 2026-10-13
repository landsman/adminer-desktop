Feature: The keyboard shortcuts tab
  A tab of the settings dialog lists the shortcuts, written the way the platform writes them — ⌘R
  on a mac, Ctrl+R elsewhere. ? opens the dialog straight on it; typing a ? into a field is typing,
  not asking for the list.

  Background:
    Given I am logged in

  Scenario: The tab lists the shortcuts in this platform's notation
    When I open the "users" table
    And I open the settings dialog
    And I open the shortcuts tab
    Then the settings dialog is open on the shortcuts tab
    And it lists reload in this platform's notation

  Scenario: ? opens settings on the shortcuts tab, and Escape closes it
    When I open the "users" table
    And I press the "?" key
    Then the settings dialog is open on the shortcuts tab
    When I press Escape
    Then the settings dialog is closed

  Scenario: ? in a field types a question mark
    When I open the edit form for "users" row 1
    And I press "?" in the first field
    Then the settings dialog is closed
