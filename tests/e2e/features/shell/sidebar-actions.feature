Feature: The sidebar's actions are icons
  SQL command, Import, Export and Create table are a row of icons under the database picker. Each
  keeps adminer's own label as its tooltip and as the name a screen reader reads.

  Scenario: Each action is an icon, named by its label
    Given the settings are at their defaults
    And I am logged in
    When I open the database
    Then the sidebar offers "SQL command, Import, Export, Create table", each an icon
    And each table is marked with an icon, in line with the actions and the labels above
