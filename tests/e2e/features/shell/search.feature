Feature: Search everywhere
  Shift twice opens one box over the tables, views, databases and schemas the sidebar knows; a few
  letters and Enter go there, without scrolling the sidebar.

  Background:
    Given I am logged in

  Scenario: Shift Shift, a few letters and Enter open another table
    When I open the "users" table
    And I press Shift twice
    And I type "orde"
    And I press the "Enter" key
    Then the "orders" table is open
