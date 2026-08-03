<?php

/**
 * Nextcloud Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Morris Jobke <hey@morrisjobke.de>
 * @copyright Morris Jobke 2015
 */

use Behat\Behat\Context\Context;
use Behat\Behat\Context\SnippetAcceptingContext;
use Behat\Gherkin\Node\TableNode;

/**
 * Defines application features from the specific context.
 */
class AmpacheContext implements Context, SnippetAcceptingContext {
	private $client;
	/** @var SimpleXMLElement */
	private $xml;
	/** @var string specifies the requested resource */
	private $resource;
	/** @var array options to pass to the Ampache API request */
	private $options = [];

	/** @var array parsed JSON of the latest request made with the JSON format */
	private $json;
	/** @var string */
	private $baseUrl;
	/** @var string */
	private $username;
	/** @var string */
	private $password;

	/** @var array maps resources to the name of the XML element of the response */
	private $resourceToXMLElementMapping = [
		'artists'  => 'artist',
		'albums'   => 'album',
		'songs'    => 'song',
		'catalogs' => 'catalog',
		'catalog'  => 'catalog',
		'live_streams'       => 'live_stream',
		'live_stream'        => 'live_stream',
		'live_stream_create' => 'live_stream',
	];

	/** @var array values picked from earlier responses, usable as ":name" in later parameters */
	private $storedValues = [];

	/** @var int HTTP status of the latest request made with the error-tolerating step */
	private $errorStatus = 0;

	/**
	 * Initializes context.
	 *
	 * Every scenario gets its own context instance.
	 * You can also pass arbitrary arguments to the
	 * context constructor through behat.yml.
	 *
	 * @param $baseUrl
	 * @param $username
	 * @param $password
	 */
	public function __construct($baseUrl, $username, $password) {
		$this->baseUrl = $baseUrl;
		$this->username = $username;
		$this->password = $password;
		$this->client = new AmpacheClient($baseUrl, $username, $password);
	}

	/**
	 * @Given I am logged in with an auth token
	 */
	public function iAmLoggedInWithAnAuthToken() {
		if (!$this->client->hasAuthToken()) {
			throw new Exception('No auth token available');
		}
	}

	/**
	 * The API version is negotiated on the handshake and bound to the session, so switching it means
	 * logging in again. Without this, all the scenarios run on the oldest supported version.
	 *
	 * @Given I am logged in with API version :version
	 */
	public function iAmLoggedInWithApiVersion($version) {
		$this->client = new AmpacheClient($this->baseUrl, $this->username, $this->password, $version);
		$this->iAmLoggedInWithAnAuthToken();
	}

	/**
	 * @When I specify the parameter :option with value :value
	 */
	public function iSpecifyTheParameterWithValue($option, $value) {
		$this->options[$option] = $this->storedValues[\ltrim($value, ':')] ?? $value;
	}

	/**
	 * Pick a value from the latest XML response so that it can be used as a parameter of a later request,
	 * referred to as ":name". This is what makes create-read-update-delete rounds expressible.
	 *
	 * @Then I store the :key of the first result as :name
	 */
	public function iStoreTheValueOfTheFirstResult($key, $name) {
		$elements = $this->xml->xpath('/root/' . $this->resourceToXMLElementMapping[$this->resource]);
		if (empty($elements)) {
			throw new Exception('No results to store from' . PHP_EOL . $this->xml->asXML());
		}
		$found = $elements[0]->xpath($key);
		if (empty($found)) {
			throw new Exception("No '$key' in the first result" . PHP_EOL . $this->xml->asXML());
		}
		$this->storedValues[$name] = $found[0]->__toString();
	}

	/**
	 * @Then the :key of the first result should contain :expected
	 */
	public function theValueOfTheFirstResultShouldContain($key, $expected) {
		$elements = $this->xml->xpath('/root/' . $this->resourceToXMLElementMapping[$this->resource]);
		if (empty($elements)) {
			throw new Exception('No results' . PHP_EOL . $this->xml->asXML());
		}
		$found = $elements[0]->xpath($key);
		if (empty($found)) {
			throw new Exception("No '$key' in the first result" . PHP_EOL . $this->xml->asXML());
		}
		$actualValue = $found[0]->__toString();
		if (\strpos($actualValue, $expected) === false) {
			throw new Exception("'$actualValue' does not contain '$expected'" . PHP_EOL . $this->xml->asXML());
		}
	}

	/**
	 * @When I request the :resource resource
	 */
	public function iRequestTheResource($resource) {
		$this->xml = $this->client->request($resource, $this->options);
		$this->resource = $resource;
	}

	/**
	 * Unlike the plain request step, this one tolerates both an error body and a non-200 HTTP status, so
	 * that the response can be asserted on rather than aborting the scenario.
	 *
	 * @When I request the :resource resource expecting an error
	 */
	public function iRequestTheResourceExpectingAnError($resource) {
		$result = $this->client->requestExpectingError($resource, $this->options);
		$this->errorStatus = $result['status'];
		$this->xml = $result['xml'];
		$this->resource = $resource;
	}

	/**
	 * @Then the response should not be an error
	 */
	public function theResponseShouldNotBeAnError() {
		$errors = $this->xml->xpath('/root/error');
		if (!empty($errors)) {
			throw new Exception('Unexpected error in the response' . PHP_EOL . $this->xml->asXML());
		}
	}

	/**
	 * @Then the response status should be :status
	 */
	public function theResponseStatusShouldBe($status) {
		if ((int)$status !== $this->errorStatus) {
			throw new Exception("Expected HTTP status $status but got {$this->errorStatus}"
								. PHP_EOL . $this->xml->asXML());
		}
	}

	/**
	 * The error code is rendered as an attribute of the `error` element while the rest of the error details
	 * are child elements of it.
	 *
	 * @Then the error code should be :code of type :type
	 */
	public function theErrorCodeShouldBe($code, $type) {
		$errors = $this->xml->xpath('/root/error');
		if (empty($errors)) {
			throw new Exception('No error in the response' . PHP_EOL . $this->xml->asXML());
		}
		$actualCode = (string)($errors[0]->attributes()['errorCode'] ?? '');
		if ($actualCode !== $code) {
			throw new Exception("Expected error code $code but got '$actualCode'" . PHP_EOL . $this->xml->asXML());
		}
		$actualType = (string)($errors[0]->errorType ?? '');
		if ($actualType !== $type) {
			throw new Exception("Expected error type $type but got '$actualType'" . PHP_EOL . $this->xml->asXML());
		}
	}

	/**
	 * @When I request the :resource resource in JSON format
	 */
	public function iRequestTheResourceInJson($resource) {
		$this->json = $this->client->requestJson($resource, $this->options);
		$this->resource = $resource;
	}

	/**
	 * @Then I should get JSON:
	 */
	public function iShouldGetJson(TableNode $table) {
		$key = $this->resourceToXMLElementMapping[$this->resource];
		if (!\array_key_exists($key, $this->json)) {
			// API versions below 5 unwrap the outermost array, so the shape depends on the negotiated version
			throw new Exception("No '$key' in the response: " . \json_encode($this->json));
		}
		$elements = $this->json[$key];

		$expectedIterator = $table->getIterator();
		foreach ($elements as $element) {
			$expectedElement = $expectedIterator->current();
			$expectedIterator->next();

			if ($expectedElement === null) {
				throw new Exception('More results than expected');
			}

			foreach ($expectedElement as $key => $expectedValue) {
				$actualValue = $element[$key];
				// the table always carries strings, so compare loosely to accept ints and bools as well
				if ($actualValue != $expectedValue) {
					throw new Exception(\ucfirst($key) . " does not match - expected: '$expectedValue'"
										. " got: '" . \var_export($actualValue, true) . "'" . PHP_EOL . \json_encode($this->json));
				}
			}
		}

		$expectedCount = \count($table->getHash());
		$actualCount = \count($elements);
		if ($expectedCount !== $actualCount) {
			throw new Exception('Not all elements are in the result set - ' . $actualCount
								. ' does not match the expected ' . $expectedCount . PHP_EOL . \json_encode($this->json));
		}
	}

	/**
	 * @Then I should get:
	 */
	public function iShouldGet(TableNode $table) {
		$elements = $this->xml->xpath('/root/'
			. $this->resourceToXMLElementMapping[$this->resource]);

		$expectedIterator = $table->getIterator();
		foreach ($elements as $element) {
			$expectedElement = $expectedIterator->current();
			$expectedIterator->next();

			if ($expectedElement === null) {
				throw new Exception('More results than expected');
			}

			foreach ($expectedElement as $key => $expectedValue) {
				$actualValue = $element->xpath($key)[0]->__toString();
				if ($actualValue !== $expectedValue) {
					throw new Exception(\ucfirst($key) . ' does not match - expected: ' . $expectedValue . ' got: ' . $actualValue . PHP_EOL . $this->xml->asXML());
				}
			}
		}

		// getHash() doesn't return the header of the table
		$expectedCount = \count($table->getHash());
		$actualCount = \count($elements);
		if ($expectedCount !== $actualCount) {
			throw new Exception('Not all elements are in the result set - ' . $actualCount . ' does not match the expected ' . $expectedCount . PHP_EOL . $this->xml->asXML());
		}
	}
}
