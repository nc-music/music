<?php

/**
 * Nextcloud Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Morris Jobke 2015
 * @copyright Pauli Järvinen 2017 - 2025
 */

use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface;

/**
 * Abstraction for the specific Ampache API calls and how to parse the result
 */
class AmpacheClient {

	/** @var string the Ampache API URL for the XML format */
	private string $baseUrl;
	/** @var string the Ampache API URL for the JSON format */
	private string $baseUrlJson;
	/** @var string auth token that is used in subsequent requests */
	private ?string $authToken;

	/**
	 * connects to the API endpoint and authenticates the user
	 *
	 * @param string $baseUrl URL of the cloud instance
	 * @param string $userName
	 * @param string $password
	 * @param string $version API version to negotiate on the handshake. The version is bound to the session,
	 *                        so it cannot be changed without logging in again. The default is the lowest
	 *                        supported version, which the app serves with its API v4 implementation.
	 * @throws AmpacheClientException if the authentication doesn't succeed
	 */
	public function __construct($baseUrl, $userName, $password, $version = '350001') {
		$this->baseUrl = $baseUrl . '/apps/music/ampache/server/xml.server.php';
		$this->baseUrlJson = $baseUrl . '/apps/music/ampache/server/json.server.php';

		$time = \time();
		$key = \hash('sha256', $password);
		$passphrase = \hash('sha256', $time . $key);

		$this->authToken = null;
		$xml = $this->request('handshake', [
			'auth'      => $passphrase,
			'timestamp' => $time,
			'version'   => $version,
			'user'      => $userName,
		]);

		$authToken = $xml->xpath('/root/auth');
		if (!$authToken) {
			throw new AmpacheClientException('No auth token in response ' . $xml->asXML());
		}

		$this->authToken = $authToken[0]->__toString();
	}

	public function hasAuthToken() : bool {
		return $this->authToken !== null;
	}

	/**
	 * requests the given Ampache method and returns an XML object
	 *
	 * @param array<string, string> $queryParams
	 * @throws AmpacheClientException if the XML couldn't be parsed or there is an error in the response
	 */
	public function request(string $method, array $queryParams = []) : \SimpleXMLElement {
		$response = $this->doRequest($this->baseUrl, $method, $queryParams);

		try {
			$xml = ClientUtil::getXml($response);
		} catch (Exception $e) {
			throw new AmpacheClientException('Could not parse XML', 0, $e);
		}

		$error = $xml->xpath('/root/error');

		if ($error) {
			throw new AmpacheClientException('Ampache error: ' . $error[0]->__toString() . ' (' . $error[0]->attributes()['code'] . ') ');
		}

		return $xml;
	}

	/**
	 * requests the given Ampache method in the JSON format and returns the parsed JSON
	 *
	 * @param array<string, string> $queryParams
	 * @throws AmpacheClientException if the JSON couldn't be parsed or there is an error in the response
	 */
	public function requestJson(string $method, array $queryParams = []) : array {
		$response = $this->doRequest($this->baseUrlJson, $method, $queryParams);

		$json = \json_decode($response->getBody(), true);

		if (!\is_array($json)) {
			throw new AmpacheClientException('Could not parse JSON');
		}

		if (isset($json['error'])) {
			$error = $json['error'];
			throw new AmpacheClientException('Ampache error: ' . ($error['errorMessage'] ?? '') . ' (' . ($error['errorCode'] ?? '') . ')');
		}

		return $json;
	}

	/**
	 * Requests an action which is expected to fail, returning the HTTP status along with the parsed body
	 * instead of throwing. This is needed for the responses which carry meaning in both the status and the
	 * error body, like the deprecated actions on API6.
	 *
	 * @param array<string, string> $queryParams
	 * @return array{status: int, xml: \SimpleXMLElement}
	 * @throws AmpacheClientException if the XML couldn't be parsed
	 */
	public function requestExpectingError(string $method, array $queryParams = []) : array {
		$response = $this->doRequest($this->baseUrl, $method, $queryParams, false);

		try {
			$xml = ClientUtil::getXml($response);
		} catch (Exception $e) {
			throw new AmpacheClientException('Could not parse XML', 0, $e);
		}

		return ['status' => $response->getStatusCode(), 'xml' => $xml];
	}

	private function doRequest(string $baseUrl, string $method, array $queryParams, bool $throwOnHttpError = true) : ResponseInterface {
		$queryParams['action'] = $method;
		if ($this->authToken !== null) {
			$queryParams['auth'] = $this->authToken;
		}

		$client = new Client(['verify' => false]);
		return $client->get($baseUrl, [
			'query' => $queryParams,
			'http_errors' => $throwOnHttpError
		]);
	}

}
