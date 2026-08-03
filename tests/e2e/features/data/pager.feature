Feature: Paging through the data list
  Adminer's run of numbered links arrives as first/previous/page/of/next/last: controls to press
  rather than a row of numbers to aim at. The ends lead nowhere on the first page, both the arrow
  and the page list move the rows in place, and the pager follows what is on screen.

  Background:
    Given I am logged in
    When I open the "documents" table 5 rows to a page

  Scenario: The first page offers the controls and says where it is
    Then the pager offers first, previous, next and last
    And the page list offers 10 pages
    And the count beside it reads 50 rows
    And the chip reads "1-5"
    And every step control is drawn from an icon file
    And first and previous lead nowhere

  Scenario: The next arrow moves the rows without rebuilding the page
    When I step to the next page
    Then the rows moved
    And the document was not rebuilt
    And the URL says page 1
    And the chip reads "6-10"
    And both ends lead somewhere

  Scenario: The page list jumps straight to the last page
    When I pick page 10 from the list
    Then the rows moved
    And the document was not rebuilt
    And the URL says page 9
