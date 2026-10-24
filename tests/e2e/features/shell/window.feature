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

  Scenario: The server's own page has the toolbar too, its links as icon buttons
    When I open the server's list of databases
    Then the toolbar's title reads "Select database"
    And the page's "Create database, Process list, Variables" are buttons in the toolbar
    And no list of links is left under the title

  Scenario: A table sorts from the toolbar's menu, which stands for adminer's Sort box
    When I open the "users" table
    And I open the toolbar's sort menu
    Then the sort menu offers "None, id, name, email, created_at, active, descending", with "None" checked
    When I pick "name" from the sort menu
    Then the URL contains "order[0]=name"
    When I open the toolbar's sort menu
    Then the sort menu offers "None, id, name, email, created_at, active, descending", with "name" checked
    When I pick "descending" from the sort menu
    Then the URL contains "desc[0]=1"

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

  Scenario: Hidden by its icon, the sidebar's show icon covers nothing, and nothing covers it
    When I open the "users" table
    And I hide the sidebar
    Then the sidebar is hidden
    And the sidebar's toggle overlaps nothing

  Scenario: On a phone-sized window the toolbar still fits, beside the burger, and the drawer is filled
    When the window is 390 pixels wide
    And I open the "users" table
    Then the toolbar's controls fit beside the path
    And the path bar starts at the window's edge
    When I open the sidebar's drawer
    Then the drawer is filled, not see-through
    When I open the list of tables
    Then the toolbar's controls fit beside the path

  Scenario: In full screen the window keeps its layout, without moving
    Given the window is full screen
    When I open the "users" table
    And I press the mouse on the empty toolbar
    Then the window was asked to move 0 times

  Scenario: In full screen every control is on screen, none cut off, covered or on another
    Given the window is full screen
    And the window is 1512 by 982 pixels
    When I open the "users" table
    Then the "sidebar, tables, sidebar toggle, settings gear, toolbar, path, search, path bar" are on screen
    When I hide the sidebar
    Then the sidebar is hidden
    And the "sidebar toggle, toolbar, path, search, path bar" are on screen
    And the sidebar's toggle overlaps nothing
    When I show the sidebar
    Then the sidebar shows
