Feature: The daily record of what an agent asked for
  Under -debug the app writes one line per request an agent makes, so "what did it actually do" has
  an answer. Rotation and retention only misbehave on a date boundary, which is not something a run
  can wait for — so the day is an argument here and the boundary is just a number.

  Background:
    Given a log directory of its own

  Scenario: A request lands as one line naming the method, the tool and the query
    When a request is logged on day "1"
    Then day "1" has its own file, named for the date
    And day "1" holds 1 lines
    And the line names the method, the tool and the query on one line

  Scenario: Writing appends rather than overwrites
    When a request is logged on day "1"
    And a second request is logged on day "1"
    Then day "1" holds 2 lines

  Scenario: Crossing midnight opens a new file and leaves yesterday alone
    When a request is logged on day "1"
    And a second request is logged on day "1"
    And a request is logged on day "2"
    Then day "1" holds 2 lines
    And day "2" holds 1 lines
    And day "2" has its own file, named for the date

  Scenario: Old days are pruned on write, recent ones are not
    Given a file from 20 days before day "2"
    And a file from 5 days before day "2"
    When a request is logged on day "2"
    Then files older than the window are gone and recent ones are kept
    And the log is readable only by its owner

  Scenario: Served without the launcher there is nowhere to write, and that is not a crash
    Then a log with nowhere to write is silent rather than broken
