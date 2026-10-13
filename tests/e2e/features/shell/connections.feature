Feature: Saved connections
  Adminer remembers the logins you made and offered them as one line of "(PostgreSQL) user@host"
  links. They are cards now, ahead of a login form folded behind "New connection", and each takes
  a name, an environment and a colour that follow you into the connection — so a PROD window does
  not look like a LOCAL one.

  Background:
    Given the settings are at their defaults
    And I am logged in
    When I go to the login page

  Scenario: The saved login is a card, and a new one is a modal with Permanent login ticked
    Then the saved connection is a card
    And the login form is folded away
    And the logo is not a link
    And the version links to adminer's releases
    When I ask for a new connection
    Then the login form is shown
    And Permanent login is ticked

  Scenario: A name, an environment and a colour stick, and follow you in
    When I label the connection "Fixture" as "prod" in "purple"
    Then the card reads "Fixture" with a "PROD" tag
    When I open the connection from its card
    Then the body carries the "env-prod" class
    And the sidebar badge reads "Fixture"

  Scenario: Picking an environment picks its colour, which stays changeable
    When I open the connection's editor
    And I pick the "prod" environment
    Then the "red" swatch is picked

  Scenario: Escape on a changed editor asks before throwing the change away
    When I open the connection's editor
    And I type "Half done" as its name
    And I press Escape
    Then I am asked whether to discard the changes
    When I keep editing
    Then the editor is still open with "Half done" in it

  Scenario: Logging out keeps the card, which asks for the password again
    When I log out of the connection
    Then the saved connection is a card
    And the card says it is logged out
    When I open the connection from its card
    Then the login form is shown

  Scenario: Forgetting takes it off the list
    When I forget the connection
    Then no connection is listed

  Scenario: A narrow window keeps the login page in one column, its modal included
    When the window is 320 by 640 pixels
    And I label the connection "A very long connection name for the production replica" as "prod" in "red"
    Then nothing runs past the window
    When I ask for a new connection
    Then nothing runs past the window
