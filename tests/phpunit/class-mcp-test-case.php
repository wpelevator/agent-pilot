<?php

namespace WPElevator\Agent_Pilot_Tests;

use WP_Abilities_Registry;
use WP_Ability_Categories_Registry;
use WP_Ability;
use WP_REST_Request;
use WP_REST_Response;
use WPElevator\Agent_Pilot\MCP\Identity;
use WPElevator\Agent_Pilot\MCP\Server;
use WPElevator\Agent_Pilot\MCP\Tools;

/**
 * Shared setup for the MCP server tests.
 */
abstract class MCP_Test_Case extends \WP_UnitTestCase {

	/**
	 * The WordPress test suite unhooks the core ability categories, so the
	 * abilities registered below need a category of their own.
	 */
	protected const CATEGORY = 'agent-pilot-test';

	protected Tools $tools;

	/**
	 * @var string[]
	 */
	private array $registered = [];

	public function set_up() {
		parent::set_up();

		$this->tools = new Tools();

		$this->register_category();

		// The endpoint is opt-in, so every test has to turn it on explicitly.
		add_filter( 'agent_pilot__mcp_enabled', '__return_true' );

		// Route registration happens on rest_api_init.
		rest_get_server();
	}

	/**
	 * The category registry is a singleton that outlives one test, so this
	 * registers the test category exactly once for the whole run.
	 */
	private function register_category(): void {
		if ( wp_has_ability_category( self::CATEGORY ) ) {
			return;
		}

		WP_Ability_Categories_Registry::get_instance()->register(
			self::CATEGORY,
			[
				'label' => 'Agent Pilot Test',
				'description' => 'Abilities registered by the Agent Pilot test suite.',
			]
		);
	}

	public function tear_down() {
		foreach ( $this->registered as $name ) {
			WP_Abilities_Registry::get_instance()->unregister( $name );
		}

		$this->registered = [];

		parent::tear_down();
	}

	/**
	 * Register an ability for the duration of one test.
	 *
	 * Goes through the registry rather than `wp_register_ability()` because that
	 * function only accepts registrations while `wp_abilities_api_init` is
	 * firing, which happened long before any test ran.
	 */
	protected function register_ability( string $name, array $args = [] ): ?WP_Ability {
		$ability = WP_Abilities_Registry::get_instance()->register(
			$name,
			array_merge(
				[
					'label' => 'Test Ability',
					'description' => 'An ability registered by the test suite.',
					'category' => self::CATEGORY,
					'execute_callback' => fn( $input ) => [ 'echoed' => $input ],
					'permission_callback' => '__return_true',
					'meta' => [
						'mcp' => [
							'public' => true,
						],
					],
				],
				$args
			)
		);

		if ( $ability ) {
			$this->registered[] = $name;
		}

		return $ability;
	}

	/**
	 * A caller authenticated by WordPress itself, so every scope is granted.
	 */
	protected function get_unscoped_identity(): Identity {
		return Identity::from_wordpress_user( 1 );
	}

	/**
	 * A caller presenting a token limited to the given scopes.
	 *
	 * @param string[] $scopes
	 */
	protected function get_token_identity( array $scopes ): Identity {
		return Identity::from_token( 1, $scopes, 'test_client' );
	}

	/**
	 * Send one JSON-RPC message to the MCP endpoint.
	 *
	 * @param array<string, string> $headers
	 */
	protected function post( array $message, array $headers = [] ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/' . Server::REST_NAMESPACE . '/' . Server::REST_ROUTE );

		$request->set_header( 'content-type', 'application/json' );

		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}

		$request->set_body( (string) wp_json_encode( $message ) );

		return $this->serve( $request );
	}

	/**
	 * Dispatch a request the way serving one actually does.
	 *
	 * `rest_do_request()` only dispatches; it does not apply `rest_post_dispatch`,
	 * which `WP_REST_Server::serve_request()` does and which the MCP server uses
	 * to answer a body the REST API rejected before the handler ever saw it.
	 */
	protected function serve( WP_REST_Request $request ): WP_REST_Response {
		return apply_filters( 'rest_post_dispatch', rest_do_request( $request ), rest_get_server(), $request );
	}

	/**
	 * A JSON-RPC request envelope.
	 */
	protected function message( string $method, array $params = [], $id = 1 ): array {
		$message = [
			'jsonrpc' => '2.0',
			'method' => $method,
		];

		if ( null !== $id ) {
			$message['id'] = $id;
		}

		if ( ! empty( $params ) ) {
			$message['params'] = $params;
		}

		return $message;
	}

	/**
	 * Tag a message as belonging to the current, handshake free revision.
	 */
	protected function as_modern( array $message ): array {
		$message['params'] = array_merge(
			$message['params'] ?? [],
			[
				'_meta' => [
					Server::META_PROTOCOL_VERSION => Server::PROTOCOL_MODERN,
				],
			]
		);

		return $message;
	}
}
