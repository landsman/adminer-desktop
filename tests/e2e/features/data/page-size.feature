Feature: Choosing how many rows a page holds
  Adminer's Limit is a number to type and a Select to press. Ours is a list: the size the page
  came with is in it and chosen, and picking another applies it on its own.

  Background:
    Given I am logged in
    When I open the "documents" table

  Scenario: Limit is a list, opened on the size the page came with
    Then Limit is a list, not a field to type in
    And it opened on "50"
    And it offers at least 5 sizes

  Scenario: Picking a smaller size applies it with no Select to press
    When I pick "10" rows a page
    Then 10 rows are listed
    And the list came back on "10"
