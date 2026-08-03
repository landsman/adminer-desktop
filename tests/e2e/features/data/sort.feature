Feature: Sorting the data list
  Clicking a heading swaps the rows in place instead of rebuilding the page, which is the whole
  reason we do it ourselves. The url is corrected to say what is on screen, so a reload agrees
  with it, and the values that arrive are coloured like the ones they replaced — Adminer
  highlights once at load, so anything swapped in afterwards is plain text unless it is asked for.

  Background:
    Given I am logged in
    When I open the "documents" table

  Scenario: Sorting reorders the rows without rebuilding the page
    When I sort by the "title" column
    Then the rows came back in a different order
    And the document was not rebuilt
    And the row count is unchanged
    And the values are still highlighted
    And the URL contains "order"

  Scenario: A reload comes back to the sorted rows
    When I sort by the "title" column
    And I reload the page
    Then the first row is unchanged
