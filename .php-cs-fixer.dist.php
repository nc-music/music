<?php

declare(strict_types=1);

require_once './vendor/autoload.php';

class NcMusicConfig extends Nextcloud\CodingStandard\Config {

	public function getRules(): array {
		$rules = parent::getRules();
		$rules['blank_line_after_opening_tag'] = false;
		$rules['spaces_inside_parentheses'] = false;
		$rules['no_spaces_inside_parenthesis'] = false;
		$rules['statement_indentation'] = false; // this currently has averse effect on parameter indentation, see https://github.com/PHP-CS-Fixer/PHP-CS-Fixer/issues/6935
		$rules['binary_operator_spaces'] = [
			'operators' => [
				'=>' => 'align_single_space_minimal',
			]
		];
		$rules['native_function_invocation'] = [
			'include' => ['@all'],
			'scope' => 'all',
			'strict' => true
		];
		return $rules;
	}
}

$config = new NcMusicConfig();
$config
	->getFinder()
	->ignoreVCSIgnored(true)
	->notPath('3rdparty')
	->notPath('build')
	->notPath('l10n')
	->notPath('vendor')
	->notPath('stubs')
	->in(__DIR__);
return $config;