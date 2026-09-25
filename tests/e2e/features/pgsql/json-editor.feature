Feature: Adminer's own JSON editor, on the edit form
  Adminer 6 dropped the json-column and pretty-json-column plugins and edits a json/jsonb column
  itself: JUSH swaps its textarea for a highlighted, pretty-printed <pre>, sized from the textarea
  it hides. So this is where a real JSON column is asserted, and where that <pre> has to land on
  the one width every field on the form shares (forms.css) rather than on a font Adminer gave it.

  A text column holding JSON is no longer anybody's business: only the plugins sniffed values.

  Background:
    Given the settings are at their defaults
    And I am logged in
    When I open the edit form for "documents" row "1"

  Scenario: A jsonb column is pretty-printed in Adminer's editor, at the form's width
    Then the "payload" field is Adminer's JSON editor
    And the "payload" field is pretty-printed
    And the "payload" field kept its accents
    And the "payload" editor is as wide as the "title" field

  Scenario: Text holding JSON is left as it is stored
    Then the "notes" field is left as Adminer's plain textarea
    And the "title" field is left as Adminer's plain textarea
