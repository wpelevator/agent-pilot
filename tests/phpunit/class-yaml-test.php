<?php

namespace WPElevator\Agent_Pilot_Tests;

use WPElevator\Agent_Pilot\Yaml;

class Yaml_Test extends \WP_UnitTestCase {

	public function test_plain_scalars_are_not_quoted() {
		$yaml = new Yaml(
			[
				'name' => 'code-review',
				'description' => 'Review changes for regressions.',
			]
		);

		$this->assertSame(
			"name: code-review\ndescription: Review changes for regressions.",
			$yaml->get_yaml(),
			'YAML-safe scalars should be emitted as plain values without quotes.'
		);
	}

	/**
	 * @dataProvider quoting_provider
	 */
	public function test_scalars_are_quoted_only_when_required( string $value, string $expected, string $message ) {
		$yaml = new Yaml( [ 'key' => $value ] );

		$this->assertSame( 'key: ' . $expected, $yaml->get_yaml(), $message );
	}

	public function quoting_provider(): array {
		return [
			'empty string' => [ '', '""', 'An empty value should be quoted to keep it from parsing as YAML null.' ],
			'double quotes' => [ 'Review "code" safely.', '"Review \"code\" safely."', 'Values containing double quotes should be quoted and escaped.' ],
			'single quotes' => [ "Don't break", "\"Don't break\"", 'Values containing single quotes should be quoted.' ],
			'mapping separator' => [ 'note: important', '"note: important"', 'A colon followed by a space would start a nested mapping without quotes.' ],
			'trailing colon' => [ 'ends with:', '"ends with:"', 'A trailing colon would leave the value looking like a mapping key.' ],
			'leading indicator' => [ '- item', '"- item"', 'A leading indicator character would change the YAML node type.' ],
			'comment' => [ 'value # comment', '"value # comment"', 'A space followed by a hash would start a YAML comment.' ],
			'newline' => [ "line one\nline two", '"line one\nline two"', 'Newlines cannot be represented in a single-line plain scalar.' ],
			'trailing whitespace' => [ 'value ', '"value "', 'Trailing whitespace survives only inside quotes.' ],
			'numeric string' => [ '42', '"42"', 'Numeric strings should be quoted to keep their string type.' ],
			'boolean string' => [ 'true', '"true"', 'Boolean-looking strings should be quoted to keep their string type.' ],
			'unicode' => [ 'Skaties piemēru', 'Skaties piemēru', 'Unicode text should stay plain and unescaped.' ],
		];
	}

	public function test_named_keys_nest_as_mappings_and_numeric_keys_become_list_items() {
		$yaml = new Yaml(
			[
				'name' => 'code-review',
				'metadata' => [
					'author' => 'WP Elevator',
					'tags' => [ 'review', 'quality: high' ],
				],
				'files' => [
					[
						'path' => 'SKILL.md',
						'required' => true,
					],
					[
						'path' => 'scripts/check.sh',
						'lines' => 42,
					],
				],
			]
		);

		$this->assertSame(
			implode(
				"\n",
				[
					'name: code-review',
					'metadata:',
					'  author: WP Elevator',
					'  tags:',
					'    - review',
					'    - "quality: high"',
					'files:',
					'  - path: SKILL.md',
					'    required: true',
					'  - path: scripts/check.sh',
					'    lines: 42',
				]
			),
			$yaml->get_yaml(),
			'Entries with named keys should nest as indented mappings while entries with numeric keys should become list items.'
		);
	}

	public function test_native_scalar_types_are_emitted_as_yaml_types() {
		$yaml = new Yaml(
			[
				'enabled' => true,
				'disabled' => false,
				'count' => 42,
				'ratio' => 0.5,
			]
		);

		$this->assertSame(
			"enabled: true\ndisabled: false\ncount: 42\nratio: 0.5",
			$yaml->get_yaml(),
			'Native booleans and numbers should be emitted as unquoted YAML booleans and numbers.'
		);
	}

	public function test_empty_arrays_are_emitted_as_empty_flow_sequences() {
		$yaml = new Yaml(
			[
				'files' => [],
				'items' => [ [] ],
			]
		);

		$this->assertSame(
			"files: []\nitems:\n  - []",
			$yaml->get_yaml(),
			'Empty arrays should be emitted as empty flow sequences both as values and as list items.'
		);
	}
}
