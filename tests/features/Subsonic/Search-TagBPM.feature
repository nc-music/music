Feature: Subsonic API - Extended song tags
  In order to see detailed metadata of a song
  As a user
  I need to see BPM

  Scenario: Search result includes BPM
    When I specify the parameter "query" with value "Médiane"
    And I request the "search2" resource
    Then the XML result should contain "song" entries:
      | title   | bpm |
      | Médiane | 122 |
