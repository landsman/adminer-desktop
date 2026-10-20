Feature: Accessibility of our own chrome
  axe-core at WCAG 2.2 AA over what this app adds — the sidebar, the toolbar, the login's
  connections, our dialogs — in both schemes, with the title bar and without it. Adminer's own
  markup is upstream's: axe's findings there are printed, not failed.

  Scenario Outline: The login page, <scheme>
    Given the browser is in the <scheme> scheme
    When I go to the login page
    Then axe finds no violations in our own chrome

    Examples:
      | scheme |
      | light  |
      | dark   |

  Scenario Outline: A table, <scheme>, <window>
    Given the browser is in the <scheme> scheme
    And <window>
    And I am logged in
    When I open the "users" table
    Then axe finds no violations in our own chrome

    Examples:
      | scheme | window                         |
      | light  | the settings are at their defaults |
      | dark   | the settings are at their defaults |
      | light  | the window has no title bar    |
      | dark   | the window has no title bar    |
