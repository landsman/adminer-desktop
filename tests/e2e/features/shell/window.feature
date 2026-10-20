Feature: The window without a title bar
  On macOS the launcher draws no title bar (installFrameless): the page runs to the window's top,
  the traffic lights sit over the sidebar, and the content's header row is the window's toolbar,
  as in Mail. Chromium has no launcher, so "the window has no title bar" stands in for what it
  adds to every page.

  Background:
    Given the settings are at their defaults
    And the window has no title bar
    And I am logged in

  Scenario: A table's actions are icon buttons in the toolbar, and its search opens the search box
    When I open the "users" table
    Then the page's "Select data, Show structure, Alter table, New item" are buttons in the toolbar
    And no list of links is left under the title
    And the toolbar's controls fit beside the path
    When I click the toolbar's search
    Then the search box is open

  Scenario: A schema's actions are icon buttons in the toolbar too
    When I open the database
    Then the page's "Alter schema, Database schema" are buttons in the toolbar
    And no list of links is left under the title

  Scenario: The way to a table is a path bar along the window's foot, as in Finder
    When I open the "users" table
    Then the path bar reads "PostgreSQL › demo › public › users", its steps leading back

  Scenario: The empty toolbar moves the window, a link in it does not
    When I open the "users" table
    And I press the mouse on the empty toolbar
    Then the window was asked to move 1 time
    When I press the mouse on one of the toolbar's buttons
    Then the window was asked to move 1 time

  Scenario: The sidebar stays hidden across pages, its toggle beside the lights
    When I open the "users" table
    And I hide the sidebar
    Then the sidebar is hidden
    And the toggle sits after the traffic lights, and the path after the toggle
    When I open the "orders" table
    Then the sidebar is hidden
    When I show the sidebar
    Then the sidebar shows

  Scenario: On a phone-sized window the toolbar still fits, beside the burger, and the drawer is filled
    When the window is 390 pixels wide
    And I open the "users" table
    Then the toolbar's controls fit beside the path
    When I open the sidebar's drawer
    Then the drawer is filled, not see-through
    When I open the list of tables
    Then the toolbar's controls fit beside the path

  Scenario: In full screen the window has its framed panels back, and neither moves it nor offers the toggle
    Given the window is full screen
    When I open the "users" table
    Then the toolbar's controls are drawn as in a window with a title bar
    When I press the mouse on the empty toolbar
    Then the window was asked to move 0 times
