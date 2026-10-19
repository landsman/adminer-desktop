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

  Scenario: The dialog is named by its visible title and takes the focus itself
    Then the settings dialog is named by its "Settings" title
    And the focus is on the settings dialog

  Scenario: The agent tab tells how to remove the registration, not only how to add it
    When I open the AI access tab
    And I tick the agent access switch
    Then the register box shows "claude mcp add -s user adminer-desktop"
    When I pick "Remove" in the register box
    Then the register box shows "claude mcp remove -s user adminer-desktop"

  Scenario: The keyboard sees which tab it is on
    When I tab into the settings dialog
    Then the focused tab shows a focus ring

  Scenario: Escape on a changed form asks before throwing it away
    When I pick the "compact" row density
    And I press Escape
    Then I am asked whether to discard the changes
    When I keep editing
    Then the settings dialog is still open with "compact" picked

  Scenario: Escape on an untouched form closes it and hands the focus back to the gear
    When I press Escape
    Then the settings dialog is closed
    And the focus is back on the gear

  Scenario: The × on an untouched form closes it and hands the focus back to the gear
    When I click the close button
    Then the settings dialog is closed
    And the focus is back on the gear

  Scenario: The × on a changed form asks before throwing it away
    When I pick the "compact" row density
    And I click the close button
    Then I am asked whether to discard the changes
    When I keep editing
    Then the settings dialog is still open with "compact" picked

  Scenario: Reduced motion turns the fade off
    When the OS asks for reduced motion
    Then the settings dialog does not fade
