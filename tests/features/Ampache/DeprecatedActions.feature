Feature: Ampache API - Deprecated genre actions
  In order to know that I must migrate to the renamed genre actions
  As a client
  I need the server to reject the pre-rename spellings the same way the original Ampache server does

  The original Ampache server dropped `tag`, `tags`, `tag_albums`, `tag_artists` and `tag_songs` from
  its method list on API5, answering them with the error 4706 and adding the HTTP status 410 on API6.
  They remain a valid part of API4, so they must keep working there.


  Scenario: The deprecated actions still work on API4
    Given I am logged in with an auth token
    When I request the "tags" resource
    Then the response should not be an error


  Scenario Outline: The deprecated actions are rejected on API6 with the HTTP status
    Given I am logged in with API version "6.6.0"
    When I request the "<action>" resource expecting an error
    Then the response status should be "410"
    And the error code should be "4706" of type "removed"

    Examples:
      | action      |
      | tag         |
      | tags        |
      | tag_albums  |
      | tag_artists |
      | tag_songs   |


  Scenario: The deprecated actions are rejected on API5 without an HTTP status
    Given I am logged in with API version "5.6.0"
    When I request the "tags" resource expecting an error
    Then the response status should be "200"
    And the error code should be "4706" of type "removed"


  Scenario: The renamed genre actions are unaffected on API6
    Given I am logged in with API version "6.6.0"
    When I request the "genres" resource
    Then the response should not be an error
