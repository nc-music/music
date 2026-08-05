Feature: Ampache API - Browse
  In order to navigate my library the way a directory browser does
  As a user
  I need to be able to walk the catalog, artist and album hierarchy one level at a time

  Note: the two catalogs are synthetic and each entity type belongs to exactly one of them, so the argument
  `catalog` either lets everything through or excludes everything.


  Scenario: The root lists the catalogs
    Given I am logged in with API version "6.6.0"
    When I specify the parameter "type" with value "root"
    And I request the "browse" resource
    Then the element "/root/child_type" should be "catalog"
    And there should be 2 "/root/browse" elements
    And the element "/root/browse[1]/name" should be "music"
    And the element "/root/browse[2]/name" should be "podcasts"


  Scenario: A music catalog is browsed into artists
    Given I am logged in with API version "6.6.0"
    When I specify the parameter "type" with value "catalog"
    And I specify the parameter "filter" with value "1"
    And I request the "browse" resource
    Then the element "/root/catalog_id" should be "1"
    And the element "/root/child_type" should be "artist"
    And there should be 3 "/root/browse" elements


  Scenario: A podcast catalog is browsed into podcasts
    Given I am logged in with API version "6.6.0"
    When I specify the parameter "type" with value "catalog"
    And I specify the parameter "filter" with value "2"
    And I request the "browse" resource
    Then the element "/root/child_type" should be "podcast"


  Scenario: An artist is browsed into albums
    Given I am logged in with API version "6.6.0"
    When I specify the parameter "type" with value "catalog"
    And I specify the parameter "filter" with value "1"
    And I request the "browse" resource
    And I store the "@id" of the first result as "artistId"
    And I specify the parameter "type" with value "artist"
    And I specify the parameter "filter" with value ":artistId"
    And I request the "browse" resource
    Then the element "/root/child_type" should be "album"
    And the element "/root/browse[1]/name" should be "The Butcher's Ballroom"


  Scenario: The type album_artist is an accepted alias of artist
    Given I am logged in with API version "6.6.0"
    When I specify the parameter "type" with value "catalog"
    And I specify the parameter "filter" with value "1"
    And I request the "browse" resource
    And I store the "@id" of the first result as "artistId"
    And I specify the parameter "type" with value "album_artist"
    And I specify the parameter "filter" with value ":artistId"
    And I request the "browse" resource
    Then the element "/root/parent_type" should be "album_artist"
    And the element "/root/child_type" should be "album"
    And the element "/root/browse[1]/name" should be "The Butcher's Ballroom"


  Scenario: The catalog of the browsed type lets the children through
    Given I am logged in with API version "6.6.0"
    When I specify the parameter "type" with value "catalog"
    And I specify the parameter "filter" with value "1"
    And I request the "browse" resource
    And I store the "@id" of the first result as "artistId"
    And I specify the parameter "type" with value "artist"
    And I specify the parameter "filter" with value ":artistId"
    And I specify the parameter "catalog" with value "1"
    And I request the "browse" resource
    Then the element "/root/browse[1]/name" should be "The Butcher's Ballroom"


  Scenario: The other catalog excludes all the children
    Given I am logged in with API version "6.6.0"
    When I specify the parameter "type" with value "catalog"
    And I specify the parameter "filter" with value "1"
    And I request the "browse" resource
    And I store the "@id" of the first result as "artistId"
    And I specify the parameter "type" with value "artist"
    And I specify the parameter "filter" with value ":artistId"
    And I specify the parameter "catalog" with value "2"
    And I request the "browse" resource
    Then the element "/root/child_type" should be "album"
    And there should be 0 "/root/browse" elements


  Scenario: A catalog which does not exist
    Given I am logged in with API version "6.6.0"
    When I specify the parameter "type" with value "artist"
    And I specify the parameter "filter" with value "1"
    And I specify the parameter "catalog" with value "99"
    And I request the "browse" resource expecting an error
    Then the error code should be "4704" of type "system"


  Scenario: An unsupported type
    Given I am logged in with API version "6.6.0"
    When I specify the parameter "type" with value "album_disk"
    And I specify the parameter "filter" with value "1"
    And I request the "browse" resource expecting an error
    Then the error code should be "4710" of type "system"


  Scenario: The IDs are rendered as strings in the JSON format
    Given I am logged in with API version "6.6.0"
    When I specify the parameter "type" with value "catalog"
    And I specify the parameter "filter" with value "1"
    And I request the "browse" resource in JSON format
    Then the JSON path "catalog_id" should be the string "1"
    And the JSON path "parent_id" should be the string "1"
    And the JSON path "browse.0.id" should be a string
    And the JSON path "browse.0.name" should be "Diablo Swing Orchestra"
