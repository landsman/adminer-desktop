Feature: The server answers only to this machine's names
  A page can point its own domain at the app's port (DNS rebinding), and the browser then treats the
  server as that page's own origin. The name in Host is the only thing that gives it away.

  Scenario Outline: A foreign name is refused, whatever it asks for
    When a request for "<path>" names the host "evil.example"
    Then the server answers 403

    Examples:
      | path                     |
      | /adminer.php             |
      | /api.php?action=sidebar  |
      | /src/Assets/css/none.css |

  Scenario Outline: This machine's names still reach the app
    When a request for "/adminer.php" names the host "<host>"
    Then the server answers 200

    Examples:
      | host      |
      | 127.0.0.1 |
      | localhost |
