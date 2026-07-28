Feature: Ampache API - Live streams
  In order to listen to internet radio on a mobile client
  As a user
  I need the playback of a live stream to go through the server, so that playlist URLs get resolved
  and the stream can be relayed

  Note: both scenarios delete the station they created, because the Subsonic suite asserts on the
  number of radio stations in the library.


  Scenario: A created live stream is played back through the server
    Given I am logged in with an auth token
    When I specify the parameter "name" with value "Behat Test Radio"
    And I specify the parameter "url" with value "http://localhost:8888/behat-test-stream.mp3"
    And I request the "live_stream_create" resource
    Then the "name" of the first result should contain "Behat Test Radio"
    And the "url" of the first result should contain "action=stream"
    And the "url" of the first result should contain "type=live_stream"
    And I store the "@id" of the first result as "stationId"
    And I specify the parameter "filter" with value ":stationId"
    And I request the "live_stream_delete" resource


  Scenario: The stream URL of a listed live stream also points at the server
    Given I am logged in with an auth token
    When I specify the parameter "name" with value "Behat Listed Radio"
    And I specify the parameter "url" with value "http://localhost:8888/behat-listed-stream.mp3"
    And I request the "live_stream_create" resource
    And I store the "@id" of the first result as "stationId"
    And I specify the parameter "filter" with value "Behat Listed Radio"
    And I request the "live_streams" resource
    Then the "url" of the first result should contain "action=stream"
    And the "url" of the first result should contain "type=live_stream"
    And I specify the parameter "filter" with value ":stationId"
    And I request the "live_stream_delete" resource
