Feature: Saved connections
  Adminer remembers the logins you made and offered them as one line of "(PostgreSQL) user@host"
  links. They are cards now, ahead of a login form folded behind "New connection", and each takes
  a name, an environment and a colour that follow you into the connection — so a PROD window does
  not look like a LOCAL one.

  Background:
    Given the settings are at their defaults
    And I am logged in
    When I go to the login page

  Scenario: The saved login is a card, and the form waits behind a button
    Then the saved connection is a card
    And the login form is folded away
    When I ask for a new connection
    Then the login form is shown

  Scenario: A name, an environment and a colour stick, and follow you in
    When I label the connection "Fixture" as "prod" in "#7a5af8"
    Then the card reads "Fixture" with a "PROD" tag
    When I open the connection from its card
    Then the body carries the "env-prod" class
    And the sidebar badge reads "Fixture"
