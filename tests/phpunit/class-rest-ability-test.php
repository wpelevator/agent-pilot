<?php

namespace WPElevator\Agent_Pilot_Tests;

use WPElevator\Agent_Pilot\Plugin;
use WPElevator\Agent_Pilot\MCP\Authentication;

require_once __DIR__ . '/class-mcp-test-case.php';

class Rest_Ability_Test extends MCP_Test_Case {

	public function set_up() {
		parent::set_up();

		// Recreate the server so rest_api_init runs inside each test's hook state.
		$GLOBALS['wp_rest_server'] = null;
		rest_get_server();
	}

	public function test_ability_is_registered_and_exposed_with_write_scope() {
		$ability = wp_get_ability( Plugin::ABILITY_REST_CALL );

		$this->assertInstanceOf( \WP_Ability::class, $ability, 'Plugin initialization should register the REST ability.' );
		$this->assertContains( 'agent-pilot.rest-call', wp_list_pluck( $this->tools->get_tools( $this->get_unscoped_identity() ), 'name' ), 'The built-in ability should be exposed as an MCP tool.' );
		$this->assertNotContains( 'agent-pilot.rest-call', wp_list_pluck( $this->tools->get_tools( $this->get_token_identity( [ Authentication::SCOPE_READ ] ) ), 'name' ), 'A generic REST tool can modify data and must require write scope even for GET calls.' );
	}

	public function test_anonymous_call_is_rejected() {
		wp_set_current_user( 0 );

		$this->assertWPError(
			wp_get_ability( Plugin::ABILITY_REST_CALL )->execute(
				[
					'method' => 'GET',
					'route' => '/',
				]
			),
			'Even public REST endpoints require authentication through the ability.'
		);
	}

	public function test_authenticated_user_can_discover_routes() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$result = wp_get_ability( Plugin::ABILITY_REST_CALL )->execute(
			[
				'method' => 'GET',
				'route' => '/',
			]
		);

		$this->assertSame( 200, $result['status'], 'An authenticated user should be able to discover REST endpoints.' );
		$this->assertArrayHasKey( '/wp/v2/posts', $result['data']['routes'], 'Discovery should include the native posts endpoint.' );
		$this->assertIsArray( $result['headers'], 'REST response headers should be preserved.' );
	}

	public function test_fields_narrows_the_response() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		self::factory()->post->create( [ 'post_title' => 'Hello' ] );

		$result = wp_get_ability( Plugin::ABILITY_REST_CALL )->execute(
			[
				'method' => 'GET',
				'route' => '/wp/v2/posts',
				'params' => [ '_fields' => 'id,title' ],
			]
		);

		$this->assertSame( 200, $result['status'], 'A filtered collection request should still succeed.' );
		$this->assertNotEmpty( $result['data'], 'The collection should contain the created post.' );
		$this->assertSame(
			[ 'id', 'title' ],
			array_keys( $result['data'][0] ),
			'_fields is how a caller controls response size, and dispatching internally must not silently ignore it.'
		);
	}

	public function test_embed_resolves_from_the_request() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		self::factory()->post->create( [ 'post_title' => 'Hello' ] );

		$result = wp_get_ability( Plugin::ABILITY_REST_CALL )->execute(
			[
				'method' => 'GET',
				'route' => '/wp/v2/posts',
				'params' => [ '_embed' => '1' ],
			]
		);

		$this->assertArrayHasKey(
			'_embedded',
			$result['data'][0],
			'Core reads _embed from the query string, which an internal request has no part in, so it has to be resolved from the request.'
		);
	}

	public function test_allow_header_reports_the_methods_a_caller_may_use() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$result = wp_get_ability( Plugin::ABILITY_REST_CALL )->execute(
			[
				'method' => 'GET',
				'route' => '/wp/v2/posts',
			]
		);

		$this->assertArrayHasKey( 'Allow', $result['headers'], 'The Allow header is how a caller learns which methods a route accepts.' );
		$this->assertStringContainsString( 'POST', $result['headers']['Allow'], 'An editor may create posts, so POST must be advertised.' );
	}

	public function test_allow_header_reflects_the_current_user_permissions() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$result = wp_get_ability( Plugin::ABILITY_REST_CALL )->execute(
			[
				'method' => 'GET',
				'route' => '/wp/v2/posts',
			]
		);

		$this->assertStringNotContainsString(
			'POST',
			$result['headers']['Allow'] ?? '',
			'A subscriber cannot create posts, so advertising POST would send an agent into a guaranteed refusal.'
		);
	}

	public function test_native_permissions_prevent_post_creation() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$result = wp_get_ability( Plugin::ABILITY_REST_CALL )->check_permissions(
			[
				'method' => 'POST',
				'route' => '/wp/v2/posts',
				'params' => [ 'title' => 'Forbidden' ],
			]
		);

		$this->assertWPError( $result, 'Endpoint permissions must reject the ability before execution.' );
		$this->assertSame( 403, $result->get_error_data()['status'], 'REST endpoint permissions must prevent subscribers from creating posts.' );
		$this->assertSame( 'rest_cannot_create', $result->get_error_code(), 'The native permission error should reach the caller.' );
		$tool_result = $this->tools->call(
			'agent-pilot.rest-call',
			[
				'method' => 'POST',
				'route' => '/wp/v2/posts',
				'params' => [ 'title' => 'Forbidden' ],
			],
			$this->get_unscoped_identity()
		);

		$this->assertWPError( $tool_result, 'MCP should reject the call at its permission boundary.' );
		$this->assertSame( 403, $tool_result->get_error_data()['status'], 'MCP should report endpoint denials as authorization failures.' );
	}

	public function test_permission_check_prepares_parameters_without_executing_endpoint() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$executed = false;
		$checked = [];
		register_rest_route(
			'agent-pilot-test/v1',
			'/permission/(?P<id>\d+)',
			[
				'methods' => 'GET',
				'args' => [
					'id' => [ 'type' => 'integer' ],
					'context' => [
						'type' => 'string',
						'default' => 'view',
					],
					'enabled' => [ 'type' => 'boolean' ],
				],
				'permission_callback' => function ( $request ) use ( &$checked ): bool {
					$checked = [ $request['id'], $request['context'], $request['enabled'] ];
					return true;
				},
				'callback' => function () use ( &$executed ): array {
					$executed = true;
					return [];
				},
			]
		);

		$result = wp_get_ability( Plugin::ABILITY_REST_CALL )->check_permissions(
			[
				'method' => 'HEAD',
				'route' => '/agent-pilot-test/v1/permission/12',
				'params' => [ 'enabled' => 'false' ],
			]
		);

		$this->assertTrue( $result, 'HEAD should fall back to the GET endpoint permission callback.' );
		$this->assertSame( [ 12, 'view', false ], $checked, 'Permissions should receive sanitized URL and query parameters plus endpoint defaults.' );
		$this->assertFalse( $executed, 'Checking ability permissions must not execute the endpoint.' );
	}

	public function test_permission_check_uses_requested_method_and_preserves_errors() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$error = new \WP_Error( 'test_denied', 'Permission denied by endpoint.', [ 'status' => 403 ] );
		register_rest_route(
			'agent-pilot-test/v1',
			'/permission',
			[
				[
					'methods' => 'GET',
					'permission_callback' => '__return_true',
					'callback' => '__return_empty_array',
				],
				[
					'methods' => 'POST',
					'permission_callback' => fn() => $error,
					'callback' => '__return_empty_array',
				],
			]
		);

		$result = wp_get_ability( Plugin::ABILITY_REST_CALL )->check_permissions(
			[
				'method' => 'POST',
				'route' => '/agent-pilot-test/v1/permission',
			]
		);

		$this->assertSame( $error, $result, 'The permission callback for the requested method should return its original WP_Error.' );
	}

	public function test_false_and_null_endpoint_permissions_deny_ability() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		foreach ( [ '__return_false', '__return_null' ] as $callback ) {
			register_rest_route(
				'agent-pilot-test/v1',
				'/permission/' . $callback,
				[
					'methods' => 'GET',
					'permission_callback' => $callback,
					'callback' => '__return_empty_array',
				]
			);

			$result = wp_get_ability( Plugin::ABILITY_REST_CALL )->check_permissions(
				[
					'method' => 'GET',
					'route' => '/agent-pilot-test/v1/permission/' . $callback,
				]
			);

			$this->assertFalse( $result, 'Both false and null endpoint permissions should deny the ability.' );
		}
	}

	public function test_body_query_and_url_parameters_reach_native_endpoints() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$ability = wp_get_ability( Plugin::ABILITY_REST_CALL );
		$created = $ability->execute(
			[
				'method' => 'POST',
				'route' => '/wp/v2/posts',
				'params' => [ 'title' => 'REST ability draft' ],
			]
		);

		$this->assertSame( 201, $created['status'], 'Editors should be able to create posts with body parameters.' );
		$this->assertArrayHasKey( 'Location', $created['headers'], 'Creation should preserve the Location header.' );

		$read = $ability->execute(
			[
				'method' => 'GET',
				'route' => '/wp/v2/posts/' . $created['data']['id'],
				'params' => [ 'context' => 'edit' ],
			]
		);

		$this->assertSame( 'REST ability draft', $read['data']['title']['raw'], 'URL parameters and the edit query context should reach the native controller.' );

		$deleted = $ability->execute(
			[
				'method' => 'DELETE',
				'route' => '/wp/v2/posts/' . $created['data']['id'],
				'params' => [ 'force' => true ],
			]
		);

		$this->assertTrue( $deleted['data']['deleted'], 'DELETE query parameters should allow permanent deletion when the user has permission.' );
	}

	public function test_rest_validation_and_missing_routes_preserve_errors() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$ability = wp_get_ability( Plugin::ABILITY_REST_CALL );
		$invalid = $ability->execute(
			[
				'method' => 'GET',
				'route' => '/wp/v2/posts',
				'params' => [ 'per_page' => 101 ],
			]
		);
		$missing = $ability->execute(
			[
				'method' => 'GET',
				'route' => '/agent-pilot-missing/v1/example',
			]
		);

		$this->assertSame( 400, $invalid['status'], 'Native parameter validation must run during dispatch.' );
		$this->assertSame( 404, $missing['status'], 'An unknown endpoint should preserve the REST 404 response.' );
	}

	public function test_input_schema_rejects_urls_and_unsupported_methods() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$ability = wp_get_ability( Plugin::ABILITY_REST_CALL );

		$this->assertWPError( $ability->check_permissions( [] ), 'Direct permission checks should validate missing input before matching REST routes.' );

		$this->assertWPError(
			$ability->execute(
				[
					'method' => 'GET',
					'route' => 'https://example.com/wp-json/',
				]
			),
			'The ability accepts internal paths rather than external URLs.'
		);
		$this->assertWPError(
			$ability->execute(
				[
					'method' => 'TRACE',
					'route' => '/',
				]
			),
			'Unsupported methods must fail schema validation.'
		);
	}
}
