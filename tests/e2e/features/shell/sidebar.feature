Feature: Resizing the sidebar
  The handle between the panels widens the sidebar, the width is persisted to the durable file, and
  a fresh page opens at it before any script runs — head() emits it into the initial HTML, which is
  the cold-start path. The keyboard has to move it too: that is what a splitter is for anyone not
  using a mouse.

  Background:
    Given the settings are at their defaults
    And I am logged in
    When I open the "users" table

  Scenario: A drag widens the sidebar, and a fresh page opens where it was left
    When I drag the sidebar handle 120 pixels right
    Then the sidebar is at least 90 pixels wider
    And the "sidebar" width is stored, matching what is on screen
    And a fresh page opens the "sidebar" at the stored width

  Scenario: The keyboard moves the splitter too
    When I drag the sidebar handle 120 pixels right
    And I nudge the sidebar handle left with the keyboard
    Then the sidebar is narrower
