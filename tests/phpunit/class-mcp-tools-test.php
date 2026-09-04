<?php

namespace WPElevator\Agent_Pilot_Tests;

use WPElevator\Agent_Pilot\MCP\Authentication;
use WPElevator\Agent_Pilot\MCP\Tools;

require_once __DIR__ . '/class-mcp-test-case.php';

class MCP_Tools_Test extends MCP_Test_Case {

	public function test_tool_name_maps_reversibly_to_ability_name() {
		$this->assertSame(
			'agent-pilot-test.read-thing',
			$this->tools->get_tool_name( 'agent-pilot-test/read-thing' ),
			'An ability name should become a tool name by swapping the slash for a dot, since MCP tool names may not contain slashes.'
		);

		$this->assertSame(
			'agent-pilot-test/read-thing',
			$this->tools->get_ability_name( 'agent-pilot-test.read-thing' ),
			'The tool name should map back to exactly the ability name it came from.'
		);
	}

	public function test_only_opted_in_abilities_are_exposed() {
		$this->register_ability( 'agent-pilot-test/opted-in' );
		$this->register_ability(
			'agent-pilot-test/not-opted-in',
			[
				'meta' => [],
			]
		);

		$names = array_keys( $this->tools->get_abilities() );

		$this->assertContains( 'agent-pilot-test/opted-in', $names, 'An ability flagged with meta.mcp.public should be exposed.' );
		$this->assertNotContains( 'agent-pilot-test/not-opted-in', $names, 'An ability without meta.mcp.public should never be exposed over MCP.' );
	}

	public function test_exposure_is_inherited_from_the_high_level_public_flag() {
		$this->register_ability(
			'agent-pilot-test/inherits-public',
			[
				'meta' => [
					'public' => true,
				],
			]
		);

		$this->assertContains(
			'agent-pilot-test/inherits-public',
			array_keys( $this->tools->get_abilities() ),
			'An ability marked meta.public with no meta.mcp should be exposed, the same way core derives show_in_rest from public and the official MCP Adapter derives MCP exposure from it.'
		);
	}

	public function test_an_explicit_mcp_flag_overrides_the_public_flag() {
		$this->register_ability(
			'agent-pilot-test/opted-out',
			[
				'meta' => [
					'public' => true,
					'mcp' => [ 'public' => false ],
				],
			]
		);

		$this->assertNotContains(
			'agent-pilot-test/opted-out',
			array_keys( $this->tools->get_abilities() ),
			'An explicit meta.mcp.public of false must win over the high-level public flag, so an ability can be published to REST while staying off MCP.'
		);
	}

	public function test_a_malformed_mcp_meta_fails_closed() {
		$this->assertFalse(
			$this->tools->is_meta_public(
				[
					'public' => true,
					'mcp' => 'yes',
				]
			),
			'Metadata that is not shaped like a channel flag should refuse exposure rather than fall through to the public flag.'
		);
	}

	public function test_a_null_mcp_flag_falls_back_to_the_public_flag() {
		$this->assertTrue(
			$this->tools->is_meta_public(
				[
					'public' => true,
					'mcp' => [ 'public' => null ],
				]
			),
			'A null channel flag counts as absent, so exposure is inherited rather than denied.'
		);
	}

	public function test_an_ability_with_no_exposure_metadata_stays_hidden() {
		$this->assertFalse(
			$this->tools->is_meta_public( [] ),
			'An ability that opted in to nothing must stay off MCP.'
		);
	}

	public function test_replacing_the_query_replaces_the_opt_in_rule() {
		$this->register_ability(
			'agent-pilot-test/not-opted-in',
			[
				'meta' => [],
			]
		);

		add_filter( 'agent_pilot__mcp_abilities', fn(): array => [ 'namespace' => 'agent-pilot-test' ] );

		$this->assertContains(
			'agent-pilot-test/not-opted-in',
			array_keys( $this->tools->get_abilities() ),
			'Replacing the query is how a site exposes abilities it does not control, so it must also replace the per-ability opt-in requirement.'
		);
	}

	public function test_abilities_are_returned_in_a_deterministic_order() {
		$this->register_ability( 'agent-pilot-test/zulu' );
		$this->register_ability( 'agent-pilot-test/alpha' );

		$names = array_values(
			array_filter(
				array_keys( $this->tools->get_abilities() ),
				fn( string $name ): bool => 0 === strpos( $name, 'agent-pilot-test/' )
			)
		);

		$this->assertSame(
			[ 'agent-pilot-test/alpha', 'agent-pilot-test/zulu' ],
			$names,
			'Tools should come back sorted by ability name so that clients can cache the list.'
		);
	}

	public function test_readonly_ability_requires_only_the_read_scope() {
		$ability = $this->register_ability(
			'agent-pilot-test/readonly',
			[
				'meta' => [
					'mcp' => [ 'public' => true ],
					'annotations' => [ 'readonly' => true ],
				],
			]
		);

		$this->assertSame(
			[ Authentication::SCOPE_READ ],
			$this->tools->get_required_scopes( $ability ),
			'An ability annotated as readonly should require only the read scope.'
		);
	}

	public function test_unannotated_ability_requires_the_write_scope() {
		$ability = $this->register_ability( 'agent-pilot-test/unannotated' );

		$this->assertSame(
			[ Authentication::SCOPE_WRITE ],
			$this->tools->get_required_scopes( $ability ),
			'An ability that does not declare itself readonly should be treated as a write, since an undescribed ability must not be assumed harmless.'
		);
	}

	public function test_annotations_map_onto_mcp_hints() {
		$ability = $this->register_ability(
			'agent-pilot-test/annotated',
			[
				'label' => 'Annotated Ability',
				'meta' => [
					'mcp' => [ 'public' => true ],
					'annotations' => [
						'readonly' => false,
						'destructive' => true,
						'idempotent' => false,
					],
				],
			]
		);

		$annotations = $this->tools->get_annotations( $ability );

		$this->assertFalse( $annotations['readOnlyHint'], 'The readonly annotation should become readOnlyHint.' );
		$this->assertTrue( $annotations['destructiveHint'], 'The destructive annotation should become destructiveHint.' );
		$this->assertFalse( $annotations['idempotentHint'], 'The idempotent annotation should become idempotentHint.' );
		$this->assertSame( 'Annotated Ability', $annotations['title'], 'The ability label should be carried as the annotation title.' );
	}

	public function test_undeclared_annotations_are_not_advertised() {
		$ability = $this->register_ability( 'agent-pilot-test/unhinted' );

		$annotations = $this->tools->get_annotations( $ability );

		$this->assertArrayNotHasKey( 'readOnlyHint', $annotations, 'An annotation the ability never declared should stay undeclared rather than become a false promise.' );
		$this->assertArrayNotHasKey( 'destructiveHint', $annotations, 'An undeclared destructive annotation should not be advertised.' );
	}

	public function test_ability_without_input_schema_gets_an_empty_object_schema() {
		$ability = $this->register_ability( 'agent-pilot-test/no-input' );

		$this->assertSame(
			[
				'type' => 'object',
				'additionalProperties' => false,
			],
			$this->tools->get_input_schema( $ability ),
			'A tool that takes no arguments should advertise the schema that accepts only an empty object.'
		);
	}

	public function test_object_input_schema_passes_through() {
		$schema = [
			'type' => 'object',
			'properties' => [
				'post_id' => [ 'type' => 'integer' ],
			],
			'required' => [ 'post_id' ],
		];

		$ability = $this->register_ability(
			'agent-pilot-test/object-input',
			[
				'input_schema' => $schema,
			]
		);

		$this->assertSame( $schema, $this->tools->get_input_schema( $ability ), 'An object input schema is already valid for MCP and should pass through untouched.' );
		$this->assertFalse( $this->tools->is_input_wrapped( $ability ), 'An object input schema should not be wrapped.' );
	}

	public function test_boolean_required_is_dropped_from_an_object_schema() {
		$ability = $this->register_ability(
			'agent-pilot-test/object-bool-required',
			[
				'input_schema' => [
					'type' => 'object',
					'properties' => [
						'post_id' => [ 'type' => 'integer' ],
					],
					'required' => true,
				],
			]
		);

		$this->assertArrayNotHasKey(
			'required',
			$this->tools->get_input_schema( $ability ),
			'JSON Schema expects required to list property names on an object, so a boolean required must be dropped rather than sent as an invalid schema.'
		);
	}

	public function test_scalar_input_schema_is_wrapped_in_an_object() {
		$ability = $this->register_ability(
			'agent-pilot-test/scalar-input',
			[
				'input_schema' => [
					'type' => 'string',
					'description' => 'The text to analyze.',
					'required' => true,
				],
			]
		);

		$schema = $this->tools->get_input_schema( $ability );

		$this->assertSame( 'object', $schema['type'], 'MCP requires an object inputSchema, so a scalar ability schema has to be wrapped.' );
		$this->assertSame( 'string', $schema['properties'][ Tools::WRAPPED_INPUT_PROPERTY ]['type'], 'The original scalar schema should become the wrapper property schema.' );
		$this->assertSame( [ Tools::WRAPPED_INPUT_PROPERTY ], $schema['required'], 'A scalar schema marked required should make the wrapper property required.' );
		$this->assertArrayNotHasKey( 'required', $schema['properties'][ Tools::WRAPPED_INPUT_PROPERTY ], 'The ability boolean required should not survive inside the wrapped property schema.' );
		$this->assertTrue( $this->tools->is_input_wrapped( $ability ), 'A non-object input schema should be reported as wrapped so the call path can unwrap it.' );
	}

	public function test_only_object_output_schemas_are_advertised() {
		$object = $this->register_ability(
			'agent-pilot-test/object-output',
			[
				'output_schema' => [
					'type' => 'object',
					'properties' => [ 'echoed' => [ 'type' => 'string' ] ],
				],
			]
		);

		$scalar = $this->register_ability(
			'agent-pilot-test/scalar-output',
			[
				'output_schema' => [ 'type' => 'string' ],
			]
		);

		$this->assertNotEmpty( $this->tools->get_output_schema( $object ), 'An object output schema should be advertised so clients can validate structured results.' );
		$this->assertSame( [], $this->tools->get_output_schema( $scalar ), 'A non-object output schema is not accepted by the legacy revisions this server also speaks, so it should not be advertised.' );
	}

	public function test_tool_definition_carries_the_ability_metadata() {
		$ability = $this->register_ability(
			'agent-pilot-test/described',
			[
				'label' => 'Described Ability',
				'description' => 'Does a described thing.',
			]
		);

		$tool = $this->tools->to_tool( $ability );

		$this->assertSame( 'agent-pilot-test.described', $tool['name'], 'The tool name should be the mapped ability name.' );
		$this->assertSame( 'Described Ability', $tool['title'], 'The ability label should become the tool title.' );
		$this->assertSame( 'Does a described thing.', $tool['description'], 'The ability description should become the tool description.' );
		$this->assertIsArray( $tool['inputSchema'], 'Every tool must carry an inputSchema, even when the ability takes no input.' );
	}

	public function test_tool_list_is_narrowed_by_the_caller_scopes() {
		$this->register_ability(
			'agent-pilot-test/read-thing',
			[
				'meta' => [
					'mcp' => [ 'public' => true ],
					'annotations' => [ 'readonly' => true ],
				],
			]
		);
		$this->register_ability( 'agent-pilot-test/write-thing' );

		$names = wp_list_pluck( $this->tools->get_tools( $this->get_token_identity( [ Authentication::SCOPE_READ ] ) ), 'name' );

		$this->assertContains( 'agent-pilot-test.read-thing', $names, 'A read-only token should still see the read tools.' );
		$this->assertNotContains( 'agent-pilot-test.write-thing', $names, 'A read-only token should never learn that the write tools exist.' );
	}

	public function test_unscoped_identity_sees_every_tool() {
		$this->register_ability( 'agent-pilot-test/write-thing' );

		$names = wp_list_pluck( $this->tools->get_tools( $this->get_unscoped_identity() ), 'name' );

		$this->assertContains( 'agent-pilot-test.write-thing', $names, 'A request authenticated by WordPress itself has no token to narrow, so every tool should be listed.' );
	}

	public function test_call_returns_text_and_structured_content() {
		$this->register_ability(
			'agent-pilot-test/structured',
			[
				'input_schema' => [
					'type' => 'object',
					'properties' => [ 'name' => [ 'type' => 'string' ] ],
				],
				'output_schema' => [
					'type' => 'object',
					'properties' => [ 'greeting' => [ 'type' => 'string' ] ],
				],
				'execute_callback' => fn( $input ) => [ 'greeting' => 'Hello ' . ( $input['name'] ?? '' ) ],
			]
		);

		$result = $this->tools->call( 'agent-pilot-test.structured', [ 'name' => 'Ada' ], $this->get_unscoped_identity() );

		$this->assertFalse( $result['isError'], 'A successful call should not be flagged as an error.' );
		$this->assertSame( [ 'greeting' => 'Hello Ada' ], $result['structuredContent'], 'An ability with an object output schema should return its result as structuredContent.' );
		$this->assertSame( '{"greeting":"Hello Ada"}', $result['content'][0]['text'], 'Structured content should also be serialized into a text block for clients that do not read structured results.' );
	}

	public function test_call_unwraps_a_wrapped_scalar_argument() {
		$this->register_ability(
			'agent-pilot-test/scalar-call',
			[
				'input_schema' => [ 'type' => 'string' ],
				'execute_callback' => fn( $input ) => strtoupper( (string) $input ),
			]
		);

		$result = $this->tools->call(
			'agent-pilot-test.scalar-call',
			[ Tools::WRAPPED_INPUT_PROPERTY => 'quiet' ],
			$this->get_unscoped_identity()
		);

		$this->assertSame( 'QUIET', $result['content'][0]['text'], 'The wrapper property should be unwrapped back into the scalar input the ability declared.' );
	}

	public function test_call_passes_no_input_to_an_ability_without_an_input_schema() {
		$this->register_ability(
			'agent-pilot-test/no-input-call',
			[
				// Core invokes the callback with no argument at all when the
				// ability declares no input schema, so the default is what runs.
				'execute_callback' => fn( $input = 'no input' ) => $input,
			]
		);

		$result = $this->tools->call( 'agent-pilot-test.no-input-call', [], $this->get_unscoped_identity() );

		$this->assertFalse(
			$result['isError'],
			'An ability with no input schema rejects any non-null input, so an empty arguments object must become null rather than the empty array MCP sent.'
		);
		$this->assertSame(
			'no input',
			$result['content'][0]['text'],
			'With no input schema the ability should be invoked without arguments rather than with an empty array.'
		);
	}

	public function test_call_reports_an_ability_failure_as_a_tool_error() {
		$this->register_ability(
			'agent-pilot-test/failing',
			[
				'execute_callback' => fn() => new \WP_Error( 'nope', 'The thing could not be done.' ),
			]
		);

		$result = $this->tools->call( 'agent-pilot-test.failing', [], $this->get_unscoped_identity() );

		$this->assertTrue( $result['isError'], 'An execution failure should be reported inside the result so the model can correct itself.' );
		$this->assertSame( 'The thing could not be done.', $result['content'][0]['text'], 'The ability error message should reach the caller.' );
	}

	public function test_call_rejects_an_unknown_tool() {
		$result = $this->tools->call( 'agent-pilot-test.missing', [], $this->get_unscoped_identity() );

		$this->assertWPError( $result, 'An unknown tool is a protocol error rather than a tool error.' );
		$this->assertSame( 404, $result->get_error_data()['status'], 'An unknown tool should be reported as not found.' );
	}

	public function test_call_refuses_an_ability_that_never_opted_in() {
		$this->register_ability(
			'agent-pilot-test/hidden',
			[
				'meta' => [],
			]
		);

		$result = $this->tools->call( 'agent-pilot-test.hidden', [], $this->get_unscoped_identity() );

		$this->assertWPError( $result, 'Resolving a tool must go through the exposed set, so an ability that never opted in stays unreachable.' );
	}

	public function test_call_refuses_a_tool_outside_the_granted_scopes() {
		$this->register_ability( 'agent-pilot-test/write-thing' );

		$result = $this->tools->call(
			'agent-pilot-test.write-thing',
			[],
			$this->get_token_identity( [ Authentication::SCOPE_READ ] )
		);

		$this->assertWPError( $result, 'A read-only token should not be able to call a write tool.' );
		$this->assertSame( 403, $result->get_error_data()['status'], 'An insufficient scope should be reported as forbidden.' );
	}

	public function test_call_refuses_when_the_ability_denies_permission() {
		$this->register_ability(
			'agent-pilot-test/denied',
			[
				'permission_callback' => '__return_false',
			]
		);

		$result = $this->tools->call( 'agent-pilot-test.denied', [], $this->get_unscoped_identity() );

		$this->assertWPError( $result, 'A scope grant never widens what the user may do, so the ability permission callback still decides.' );
		$this->assertSame( 403, $result->get_error_data()['status'], 'A denied permission callback should be reported as forbidden.' );
	}
}
