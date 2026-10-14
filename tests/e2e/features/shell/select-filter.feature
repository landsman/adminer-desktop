Feature: Filter a long select by typing
  A native select only jumps to the first option starting with what is typed. The database and
  schema switches, and any select of fifteen options or more, narrow to the options holding the
  letters anywhere in the name.

  Background:
    Given I am logged in

  Scenario: Letters from the middle of a schema's name, and Enter goes there
    When I open the schema switch
    And I type "format"
    And I press the "Enter" key
    Then the "information_schema" schema is open
