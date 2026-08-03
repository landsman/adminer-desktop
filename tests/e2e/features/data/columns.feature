Feature: Resizing a column of the data list
  Two drags, because they do different things. A narrow column widens on its own, with no query
  behind it: its neighbours keep what they had, the table grows and scrolls inside the panel, and
  the width outlives a reload — from sessionStorage, with nothing written to the durable file,
  which is the whole point of where it is kept.

  A column whose values were already cut to fit is the other case: that one has to raise Adminer's
  Text length and run the query again, or the space it just won comes back empty.

  Background:
    Given the settings are at their defaults
    And I am logged in
    When I open the "documents" table

  Scenario: A drag widens one column and leaves the rest of the table alone
    When I drag the "title" column 150 pixels wider
    Then the column is at least 120 pixels wider
    And the other columns kept their width
    And the table grew with it
    And the table scrolls inside the content panel
    And no rows were selected
    And Text length was left alone

  Scenario: The grip belongs to the column, not to the heading
    When I drag the "title" column 150 pixels wider
    Then the grip runs the height of the column
    And the grip stops where the list does

  Scenario: A reload comes back to the dragged width
    When I drag the "title" column 150 pixels wider
    And I reload the page
    Then the column is still at the dragged width

  Scenario: Widening a cut-off column fetches the text to fill it
    When I drag the "payload" column 200 pixels wider and the query runs again
    Then Text length was raised to cover the column
    And longer values arrived
    And the values are still highlighted
    And the column kept the width it was dragged to
    And no column width reached the stored settings
