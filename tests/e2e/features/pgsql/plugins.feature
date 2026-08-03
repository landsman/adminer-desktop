Feature: The picked plugins, on a form
  check.sh proves every shipped plugin boots, but only on the login page — it never sees what one
  does to a form, so a plugin that had silently stopped applying would still pass it.

  PostgreSQL only, because the answers are the driver's: Adminer gives a real jsonb column its own
  jush-js editor, so `payload` looks the same whether a JSON plugin is there or not. The column that
  proves anything is `notes` — text holding JSON, where only a plugin can be doing the work, since
  these plugins sniff the value rather than the column type.

  Background:
    Given the settings are at their defaults
    And I am logged in

  Scenario: A constructor argument reaches the plugin, and is respected past its limit
    Given only the "edit-foreign" plugin is on
    When I open the edit form for "orders" row "1"
    Then the "user_id" field is a dropdown
    When I open the edit form for "big_child" row "1"
    Then the "lookup_id" field is left as a plain input

  Scenario: Pretty-printing takes over a JSON value and leaves plain text alone
    Given only the "pretty-json-column" plugin is on
    When I open the edit form for "documents" row "1"
    Then the "notes" field is the plugin's own editor
    And the "notes" field is pretty-printed
    And the "notes" field kept its accents
    And the "title" field is left as Adminer's own

  Scenario: The key table is echoed beside Adminer's own input
    Given only the "json-column" plugin is on
    When I open the edit form for "documents" row "1"
    Then the "payload" field lists the keys "customer,paid"
    And the "notes" field lists the keys "author,revision"
    And the "title" field got no table of keys
