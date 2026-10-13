Feature: The keyboard shortcuts dialog
  ? lists the shortcuts, written the way the platform writes them — ⌘R on a mac, Ctrl+R elsewhere.
  Typing a ? into a field is typing, not asking for the list.

  Background:
    Given I am logged in

  Scenario: ? opens the list, and Escape closes it
    When I open the "users" table
    And I press the "?" key
    Then the shortcuts dialog is open
    And it lists reload in this platform's notation
    When I press Escape
    Then the shortcuts dialog is closed

  Scenario: ? in a field types a question mark
    When I open the edit form for "users" row 1
    And I press "?" in the first field
    Then the shortcuts dialog is closed
