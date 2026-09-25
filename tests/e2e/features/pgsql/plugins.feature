Feature: The picked plugins, on a form
  check.sh proves every shipped plugin boots, but only on the login page — it never sees what one
  does to a form, so a plugin that had silently stopped applying would still pass it.

  Background:
    Given the settings are at their defaults
    And I am logged in

  Scenario: A constructor argument reaches the plugin, and is respected past its limit
    Given only the "edit-foreign" plugin is on
    When I open the edit form for "orders" row "1"
    Then the "user_id" field is a dropdown
    When I open the edit form for "big_child" row "1"
    Then the "lookup_id" field is left as a plain input
