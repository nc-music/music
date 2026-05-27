<?php declare(strict_types=1);

/**
 * Nextcloud Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Pauli Järvinen 2026
 */

namespace OCA\Music\Service\Ampache;

use OCA\Music\BusinessLayer\PlaylistBusinessLayer;
use OCA\Music\Middleware\AmpacheException;
use OCA\Music\Service\AdvSearchRules;
use OCP\IL10N;

class AmpacheAdvSearch {

	public function __construct(
		private IL10N $l10n,
		private AdvSearchRules $advSearchRules,
		private PlaylistBusinessLayer $playlistBusinessLayer
	) {
	}

	/**
	 * Get the available search rules for the given entity type, along with their types and widget information.
	 * @return array<array{name: string, label: string, type: string, widget: array{0: string, 1: array|string}, title: string}>
	 */
	public function searchRules(string $entityType, string $userId) : array {
		$allRules = $this->advSearchRules->getRules();

		// some entity types have different names in the Ampache API compared to the internal ones
		$entityType = match ($entityType) {
			'song' => 'track',
			'podcast' => 'podcast_channel',
			'live_stream' => 'radio_station',
			default => $entityType
		};

		$result = [];

		foreach ($allRules[$entityType] as $title => $rules) {
			foreach ($rules as $name => $label) {
				$type = self::typeForRule($name);
				$widget = $this->widgetForRuleType($type, $userId);
				$result[] = [
					'name' => $name,
					'label' => $label,
					'type' => self::ampacheRuleType($type),
					'widget' => $widget,
					'title' => $title
				];
			}
		}

		return $result;
	}

	/**
	 * Parse and convert the search rules given as HTTP parameters to a structured format that can be used for searching.
	 * @param array<string> $restParams
	 * @return array<array{rule: string, operator: string, input: string}>
	 */
	public static function getAndConvertRules(array $restParams) : array {
		// organize the rule parameters from the HTTP call
		$rules = self::getRuleParams($restParams);

		// apply some conversions on the rules
		foreach ($rules as &$rule) {
			$rule['rule'] = self::resolveRuleAlias($rule['rule']);
			$rule['operator'] = self::interpretOperator($rule['operator'], $rule['rule']);
		}

		return $rules;
	}

	/**
	 * @return array<array{rule: string, operator: int, input: string}>
	 */
	private static function getRuleParams(array $urlParams) : array {
		$rules = [];

		// read and organize the rule parameters
		foreach ($urlParams as $key => $value) {
			$parts = \explode('_', $key, 3);
			if ($parts[0] == 'rule' && \count($parts) > 1) {
				if (\count($parts) == 2) {
					$rules[$parts[1]]['rule'] = $value;
				} elseif ($parts[2] == 'operator') {
					$rules[$parts[1]]['operator'] = (int)$value;
				} elseif ($parts[2] == 'input') {
					$rules[$parts[1]]['input'] = $value;
				}
			}
		}

		// validate the rule parameters
		if (\count($rules) === 0) {
			throw new AmpacheException('At least one rule must be given', 400);
		}
		foreach ($rules as $rule) {
			if (\count($rule) != 3) {
				throw new AmpacheException('All rules must be given as triplet "rule_N", "rule_N_operator", "rule_N_input"', 400);
			}
		}

		return $rules;
	}

	private static function resolveRuleAlias(string $rule) : string {
		switch ($rule) {
			case 'name':					return 'title';
			case 'song_title':				return 'song';
			case 'album_title':				return 'album';
			case 'artist_title':			return 'artist';
			case 'podcast_title':			return 'podcast';
			case 'podcast_episode_title':	return 'podcast_episode';
			case 'album_artist_title':		return 'album_artist';
			case 'song_artist_title':		return 'song_artist';
			case 'tag':						return 'genre';
			case 'song_tag':				return 'song_genre';
			case 'album_tag':				return 'album_genre';
			case 'artist_tag':				return 'artist_genre';
			case 'no_tag':					return 'no_genre';
			default:						return $rule;
		}
	}

	// NOTE: alias rule names should be resolved to their base form before calling this
	private static function interpretOperator(int $rule_operator, string $rule) : string {
		// Operator mapping is different for different types of rules
		$type = self::ampacheRuleType(self::typeForRule($rule));

		$mapping = [
			'text' => [
				0 => 'contain',		// contains
				1 => 'notcontain',	// does not contain;
				2 => 'start',		// starts with
				3 => 'end',			// ends with;
				4 => 'is',			// is
				5 => 'isnot',		// is not
				6 => 'sounds',		// sounds like
				7 => 'notsounds',	// does not sound like
				8 => 'regexp',		// matches regex
				9 => 'notregexp'	// does not match regex
			],
			'numeric' => [
				0 => '>=',
				1 => '<=',
				2 => '=',
				3 => '!=',
				4 => '>',
				5 => '<'
			],
			'numeric_limit' => [
				0 => 'limit'
			],
			'date' => [
				0 => 'before',
				1 => 'after'
			],
			'days' => [
				0 => 'before',
				1 => 'after'
			],
			'boolean' => [
				0 => 'true',
				1 => 'false'
			],
			'boolean_numeric' => [
				0 => 'equal',
				1 => 'ne'
			]
		];

		return $mapping[$type][$rule_operator]
			?? throw new AmpacheException("Search operator '$rule_operator' not supported for '$type' type rule '$rule", 400);
	}

	private static function typeForRule(string $rule) : string {
		return AdvSearchRules::typeForRule($rule) ?? throw new AmpacheException("Search rule '$rule' not supported", 400);
	}

	private static function ampacheRuleType(string $type) : string {
		return match ($type) {
			'numeric_rating' => 'numeric',
			'playlist' => 'boolean_numeric',
			default => $type
		};
	}

	private function widgetForRuleType(string $type, string $userId) : array {
		if ($type == 'numeric_rating') {
			return ['select', \array_map(fn($val) => $this->l10n->n('%n Star', '%n Stars', $val), [0,1,2,3,4,5])];
		} elseif ($type == 'text') {
			return ['input', 'text'];
		} elseif (\in_array($type, ['numeric', 'numeric_limit', 'days', 'boolean_numeric'])) {
			return ['input', 'number'];
		} elseif ($type == 'date') {
			return ['input', 'datetime-local'];
		} elseif ($type == 'boolean') {
			return ['input', 'hidden'];
		} elseif ($type == 'playlist') {
			$playlists = $this->playlistBusinessLayer->findAll($userId);
			$options = [];
			foreach ($playlists as $playlist) {
				$options[(string)$playlist->getId()] = $playlist->getName();
			}
			return ['select', $options];
		}
		throw new \LogicException("Unexpected type '$type'");
	}
}