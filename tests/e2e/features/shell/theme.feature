Feature: The Adminer Desktop theme
  Both schemes are one set of light-dark() tokens, resolved by the color-scheme Adminer takes from
  our meta. So it is not enough that a token is defined: a real surface has to have resolved to the
  side the OS asked for, and the scheme has to have been emulated at all — otherwise a dark run
  renders light and the screenshot is the only tell.

  Scenario Outline: The theme applies and follows the scheme the OS asks for
    Given the browser is in the <scheme> scheme
    And I am logged in
    When I open the "users" table
    Then the theme is applied
    And the emulated scheme is <scheme>
    And the surface resolves to the <scheme> scheme

    Examples:
      | scheme |
      | light  |
      | dark   |

  Scenario: The settings gear scrolls with the sidebar it sits in
    Given I am logged in
    When I open the "users" table
    Then the settings gear scrolls with the sidebar
