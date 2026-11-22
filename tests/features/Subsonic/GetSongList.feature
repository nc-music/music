Feature: Subsonic API - Get song list
  In order to browse my music collection
  As a user
  I need to be able to list my songs by various criteria


  Scenario: List latest songs
    When I specify the parameter "type" with value "newest"
    And I request the "getSongList" resource
    Then I should get XML containing 10 "song" entries


  Scenario: List limited number of latest songs
    When I specify the parameter "type" with value "newest"
    And I specify the parameter "size" with value "3"
    And I request the "getSongList" resource
    Then I should get XML containing 3 "song" entries


  Scenario: List second page of latest songs
    When I specify the parameter "type" with value "newest"
    And I specify the parameter "size" with value "5"
    And I specify the parameter "offset" with value "2"
    And I request the "getSongList" resource
    Then I should get XML containing 5 "song" entries


  Scenario: List all songs with a high limit
    When I specify the parameter "type" with value "newest"
    And I specify the parameter "size" with value "1000"
    And I request the "getSongList" resource
    Then I should get XML containing 13 "song" entries


  Scenario: List most recently played song
    Given I have stored "id" from the "song" matching "Heroines"
    When I specify the parameter "id" with the stored value
    And I request the "scrobble" resource
    And I specify the parameter "type" with value "recent"
    And I specify the parameter "size" with value "1"
    And I request the "getSongList" resource
    Then I should get XML with "song" entries:
      | title    |
      | Heroines |


  Scenario: List most frequently played song
    Given I have stored "id" from the "song" matching "Heroines"
    When I specify the parameter "id" with the stored value
    And I request the "scrobble" resource
    And I specify the parameter "type" with value "frequent"
    And I specify the parameter "size" with value "1"
    And I request the "getSongList" resource
    Then I should get XML with "song" entries:
      | title    |
      | Heroines |


  Scenario: List songs alphabetically by artist
    When I specify the parameter "type" with value "alphabeticalByArtist"
    And I specify the parameter "size" with value "3"
    And I request the "getSongList" resource
    Then I should get XML with "song" entries:
      | title           | artist                 |
      | Balrog Boogie   | Diablo Swing Orchestra |
      | Gunpowder Chant | Diablo Swing Orchestra |
      | Heroines        | Diablo Swing Orchestra |


  Scenario: List songs of a genre
    When I specify the parameter "type" with value "byGenre"
    And I specify the parameter "genre" with value "Classical"
    And I specify the parameter "size" with value "1000"
    And I request the "getSongList" resource
    Then I should get XML containing 5 "song" entries
