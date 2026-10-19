Feature: An agent reading a value MySQL hands over as raw bytes
  mysqli returns a BLOB or BINARY value as the bytes themselves, and one byte that is not UTF-8
  failed json_encode() for the whole answer, which went out as an empty text block — no error,
  nothing to tell it from no rows. Postgres sends bytea as hex text, so this is mysql only.

  Background:
    Given AI access is on
    And a window is logged in to the demo database

  Scenario: A binary value comes back as hex beside the text ones, and the answer says so
    When an agent runs the query "SELECT X'FF00' AS hash, 'Anna' AS name"
    Then the value in "hash" comes back as hex "ff00", 2 bytes long
    And the answer mentions "Anna"
