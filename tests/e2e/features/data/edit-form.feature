Feature: Resizing the fields of the edit form
  A field is dragged by its own native grip, and the width lands on every field on the form rather
  than the one dragged — the JSON column's JUSH <pre> included, which carries an inline width no
  stylesheet can reach. It is persisted to the durable file, and a fresh page opens at it before
  any script runs: the cold-start path head() drives.

  Background:
    Given the settings are at their defaults
    And I am logged in
    When I open the edit form for "documents" row "1"

  Scenario: The dragged width lands on every field and survives a cold start
    When I drag the first field 150 pixels wider
    Then the field is at least 120 pixels wider
    And every other field on the form followed it
    And the "edit_field" width is stored, matching what is on screen
    And a fresh page opens the "edit_field" at the stored width
