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
    And the version and the gear are centred as a pair
    When I ask for a new connection
    Then the login form is shown
    And Permanent login is ticked
    And logging in waits in the modal from the form, and under the activity indicator from a card

  Scenario: Without a title bar the version and the gear are still at the foot
    Given the window has no title bar
    When I go to the login page
    Then the version and the gear are centred as a pair
    And nothing masks out the version and the gear

  Scenario: A login that fails says why in the modal, with what was typed still in it
    When I log in with the password "not-the-password"
    Then the new connection says why, with what I typed still in it

  Scenario: A login that waits shows it in the modal, then says why it failed
    When I log in to a server that does not answer
    Then the login waits in the modal, with Cancel turning in place of Login
    And the new connection says why, with what I typed still in it

  Scenario: A login that waits can be cancelled, and leaves nothing waiting behind it
    When I log in to a server that does not answer
    And I cancel the login
    Then the login waited in the modal, with Cancel turning in place of Login
    And the login stops waiting, with what I typed still in it
    And the next page in the session answers straight away

  Scenario: A Cancel before the login is sent keeps it from going out
    Then a Cancel before the login is sent keeps it from being sent

  Scenario: A name, an environment and a colour stick, and follow you in
    When I label the connection "Fixture" as "prod" in "purple"
    Then the card reads "Fixture" with a "PROD" tag
    When I open the connection from its card
    Then the body carries the "env-prod" class
    And the connection badge reads "Fixture"
    # The colour's stripe marks only the pages that change something.
    When I open the "users" table
    Then the body does not carry the "ad-writing" class
    When I open the edit form for "users" row 1
    Then the body carries the "ad-writing" class

  Scenario: The connection badge leads back to the saved connections
    When I open the connection from its card
    And I click the connection badge
    Then the saved connection is a card

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

  Scenario: A connection added anew offers its name as soon as it connects
    When I forget the connection
    And I connect to it again
    Then I am offered to name it
    When I name it "Fixture" as "prod" in "purple"
    Then the connection badge reads "Fixture"
    And the body carries the "env-prod" class
    When I go to the login page
    Then the card reads "Fixture" with a "PROD" tag

  Scenario: A narrow window keeps the login page in one column, its modal included
    When the window is 320 by 640 pixels
    And I label the connection "A very long connection name for the production replica" as "prod" in "red"
    Then nothing runs past the window
    When I ask for a new connection
    Then nothing runs past the window
