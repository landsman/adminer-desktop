Feature: The settings dialog
  Every change made in the dialog has to survive Save, and this is a regression guard as much as a
  feature: the language <select> is relocated into the settings form for layout, and while it still
  carried name="lang", Save posted lang too — Adminer's lang.inc.php treats any request carrying it
  as a language switch and redirects before the settings are applied, so nothing saved at all.

  Background:
    Given the settings are at their defaults
    And I am logged in
    When I open the "users" table
    And I open the settings dialog

  Scenario: Row density reaches the body and the durable file
    When I pick the "compact" row density
    And I save the settings
    Then the body carries the "density-compact" class
    And "density" is stored as "compact"

  Scenario: A gallery design is linked once it is saved
    When I pick the first gallery design
    And I save the settings
    Then the chosen design is linked

  Scenario: A plugin is remembered when it is ticked, and forgotten when it is not
    When I tick the "row-numbers" plugin
    And I save the settings
    Then the "row-numbers" plugin is stored as enabled
    When I open the settings dialog
    And I untick the "row-numbers" plugin
    And I save the settings
    Then the "row-numbers" plugin is no longer stored

  Scenario: The language switch still reloads the page on its own
    When I switch the language to "de"
    Then the page comes back in "de"

  Scenario: Forcing Dark pins the dark scheme under a light OS
    When I force the "dark" appearance
    And I save the settings
    Then the body carries the "theme-dark" class
    And the override renders dark under a light OS

  Scenario: Reset forgets the dialog's own fields and what the api stored
    Given a dragged sidebar width has been stored
    When I pick the "compact" row density
    And I save the settings
    And I open the settings dialog
    And I reset the settings to their defaults
    Then nothing is stored any more
    And the body carries the "density-cozy" class
