Feature: Our chrome under every gallery design
  A gallery design knows nothing of the gear or the rest of what we add to adminer's page, and the
  gear is the only way back to the settings: a design that loses it strands the user on that design.

  Background:
    Given the settings are at their defaults

  Scenario: Every gallery design keeps the settings gear reachable
    Given I am logged in
    When I open the "users" table
    Then every gallery design shows a settings gear that opens the dialog

  Scenario: Every gallery design keeps the settings gear reachable without a title bar
    Given the window has no title bar
    And I am logged in
    When I open the "users" table
    Then every gallery design shows a settings gear that opens the dialog

  Scenario: Every gallery design keeps the settings gear reachable on the login page
    When I go to the login page
    Then every gallery design shows a settings gear that opens the dialog
