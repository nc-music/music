Feature: Ampache API - Catalogs
  In order to browse my collection with a client which navigates by catalog
  As a user
  I need to be able to list the catalogs of the library

  Note: the id is an attribute rather than a child element in the XML format, hence the "@id" columns.


  Scenario: List all catalogs
    Given I am logged in with an auth token
    When I request the "catalogs" resource
    Then I should get:
      | @id      | name     | type  | gather_types |
      | music    | Music    | local | music        |
      | podcasts | Podcasts | local | podcast      |


  Scenario: List catalogs filtered by gather type
    Given I am logged in with an auth token
    When I specify the parameter "filter" with value "podcast"
    And I request the "catalogs" resource
    Then I should get:
      | @id | name     | type  | gather_types |
      | podcasts | Podcasts | local | podcast      |


  Scenario: List catalogs with a gather type which we have no catalog for
    Given I am logged in with an auth token
    When I specify the parameter "filter" with value "video"
    And I request the "catalogs" resource
    Then I should get:
      | @id | name | type | gather_types |


  Scenario: List one catalog with limit
    Given I am logged in with an auth token
    When I specify the parameter "limit" with value "1"
    And I request the "catalogs" resource
    Then I should get:
      | @id   | name  | type  | gather_types |
      | music | Music | local | music        |


  Scenario: Get a single catalog by id
    Given I am logged in with an auth token
    When I specify the parameter "filter" with value "podcasts"
    And I request the "catalog" resource
    Then I should get:
      | @id      | name     | type  | gather_types |
      | podcasts | Podcasts | local | podcast      |


  Scenario: List all catalogs in the JSON format on API version 6
    Given I am logged in with API version "6.6.0"
    When I request the "catalogs" resource in JSON format
    Then I should get JSON:
      | id       | name     | type  | gather_types | enabled | rename_pattern | sort_pattern |
      | music    | Music    | local | music        | 1       |                |              |
      | podcasts | Podcasts | local | podcast      | 1       |                |              |
