Feature: Subsonic API - Extended song tags
  In order to see detailed metadata of a song
  As a user
  I need to see BPM and composer in search results

  Scenario: Search result includes BPM and composer
    When I specify the parameter "query" with value "Médiane"
    And I request the "search2" resource
    Then the XML result should contain "song" entries:
      | title   | bpm | composer       |
      | Médiane | 122 | Pascal Boiseau (Pascalb) |
