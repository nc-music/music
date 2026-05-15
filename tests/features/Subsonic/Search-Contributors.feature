Feature: Subsonic API - Song contributors (OpenSubsonic)
  In order to see structured contributor metadata
  As a user
  I need to see the composer and performer listed as contributors in search results

  Scenario: Search result includes composer and performer as contributors
    When I specify the parameter "query" with value "Médiane"
    And I request the "search2" resource
    And the first "song" XML element should have a "contributors" child with attribute "role" value "composer"
    And the first "song" XML element should have a "contributors/artist" child with attribute "name" value "Pascal Boiseau (Pascalb)"
    And the first "song" XML element should have a "contributors" child with attribute "role" value "performer"

  Scenario: Search result includes displayComposer
    When I specify the parameter "query" with value "Médiane"
    And I request the "search2" resource
    Then the XML result should contain "song" entries:
      | title   | displayComposer          |
      | Médiane | Pascal Boiseau (Pascalb) |
