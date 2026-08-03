Feature: Dropping a dump on the window to import it
  The dropzone belongs to the import page and nowhere else, so on any other page the overlay is
  never built at all. On the import page a file dragged over the window raises the affordance, and
  dropping it hands the file to Adminer's own upload input — its existing import takes over from
  there — while the browser's own "navigate to the file" default is called off.

  Background:
    Given I am logged in

  Scenario: The dropzone stays off every other page
    When I open the "users" table
    Then the import dropzone was never built

  Scenario: A file dragged over the import page is taken by Adminer's own upload
    When I open the import page
    Then the dropzone is ready and out of the way
    When I drag a file over the window
    Then the drop affordance is raised
    When I drop it
    Then the file landed on Adminer's own upload input
    And the browser did not navigate to the file
    And the overlay fell away
