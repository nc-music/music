Feature: Ampache API - Now playing
  In order to let the server know what I am listening to
  As a user
  I need to be able to report my playback state and to read it back

  Note that unlike on the original Ampache server, which reports what every user of the instance is
  playing, our `now_playing` is scoped to the requesting user. Every other part of this app keeps one
  user's library invisible to the others, and reporting across users would break that.

  The state is the one shared with the web UI and the Subsonic API, so a song started in the browser
  shows up here as well. It is track-based, and the type `podcast_episode` is hence not supported.


  Scenario: Nothing is playing to begin with
    Given I am logged in with an auth token
    When I request the "now_playing" resource
    Then I should get:
      | id | type |


  Scenario: Reporting playback makes the song show up as now playing
    Given I am logged in with an auth token
    When I specify the parameter "limit" with value "1"
    And I request the "songs" resource
    And I store the "@id" of the first result as "songId"
    And I specify the parameter "filter" with value ":songId"
    And I specify the parameter "client" with value "Behat"
    And I request the "player" resource
    Then the "type" of the first result should contain "song"
    And the "client" of the first result should contain "Behat"
    And the "user/username" of the first result should contain ":username"


  Scenario: The reported song is still playing on a later request
    Given I am logged in with an auth token
    When I specify the parameter "limit" with value "1"
    And I request the "songs" resource
    And I store the "@id" of the first result as "songId"
    And I specify the parameter "filter" with value ":songId"
    And I request the "player" resource
    And I request the "now_playing" resource
    Then the "type" of the first result should contain "song"


  Scenario: A play reported outside the player action shows up as now playing
    Given I am logged in with an auth token
    When I specify the parameter "limit" with value "1"
    And I request the "songs" resource
    And I store the "@id" of the first result as "songId"
    And I specify the parameter "filter" with value ":songId"
    And I specify the parameter "state" with value "stop"
    And I request the "player" resource
    And I specify the parameter "id" with value ":songId"
    And I request the "record_play" resource
    And I request the "now_playing" resource
    Then the "type" of the first result should contain "song"


  Scenario: Reporting a stop clears the now playing state
    Given I am logged in with an auth token
    When I specify the parameter "limit" with value "1"
    And I request the "songs" resource
    And I store the "@id" of the first result as "songId"
    And I specify the parameter "filter" with value ":songId"
    And I request the "player" resource
    And I specify the parameter "state" with value "stop"
    And I request the "player" resource
    Then I should get:
      | id | type |


  Scenario: An unsupported media type is rejected
    Given I am logged in with API version "6.6.0"
    When I specify the parameter "filter" with value "1"
    And I specify the parameter "type" with value "video"
    And I request the "player" resource expecting an error
    Then the error code should be "4710" of type "system"


  Scenario: Podcast episodes are rejected, as the shared state is track-based
    Given I am logged in with API version "6.6.0"
    When I specify the parameter "filter" with value "1"
    And I specify the parameter "type" with value "podcast_episode"
    And I request the "player" resource expecting an error
    Then the error code should be "4710" of type "system"


  Scenario: An invalid playback state is rejected
    Given I am logged in with API version "6.6.0"
    When I specify the parameter "filter" with value "1"
    And I specify the parameter "state" with value "rewind"
    And I request the "player" resource expecting an error
    Then the error code should be "4710" of type "system"
