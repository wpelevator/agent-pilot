<?php

namespace WPElevator\Agent_Pilot;

use WP_Error;
use WP_REST_Request;

/** Dispatches internal REST requests as the current WordPress user. */
class Rest_Ability {

	public const NAME = 'agent-pilot/rest-call';
	public const CATEGORY = 'agent-pilot';

	public function init(): void {
		add_action( 'wp_abilities_api_categories_init', [ $this, 'action_register_category' ] );
		add_action( 'wp_abilities_api_init', [ $this, 'action_register_ability' ] );
	}

	public function action_register_category(): void {
		wp_register_ability_category(
			self::CATEGORY,
			[
				'label' => __( 'Agent Pilot', 'wpelevator-agent-pilot' ),
				'description' => __( 'Interact with this WordPress site.', 'wpelevator-agent-pilot' ),
			]
		);
	}

	public function action_register_ability(): void {
		wp_register_ability(
			self::NAME,
			[
				'label' => __( 'Call REST API', 'wpelevator-agent-pilot' ),
				'description' => __( 'Execute a WordPress REST API endpoint internally. Call OPTIONS on a route for its methods and parameter schema; GET / lists every route on the site but is large. Use _fields on any call to limit the response. Route keys in the index are regular expressions, so /wp/v2/posts/(?P<id>[\d]+) is called as /wp/v2/posts/123. Pass parameters separately from the route.', 'wpelevator-agent-pilot' ),
				'category' => self::CATEGORY,
				'input_schema' => [
					'type' => 'object',
					'properties' => [
						'method' => [
							'type' => 'string',
							'enum' => [ 'GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS' ],
						],
						'route' => [
							'type' => 'string',
							'pattern' => '^/[^?#]*$',
							'description' => __( 'REST route path, such as /wp/v2/posts, without a URL or query string.', 'wpelevator-agent-pilot' ),
						],
						'params' => [
							'type' => 'object',
							'additionalProperties' => true,
							'description' => __( 'Query parameters for GET, HEAD and DELETE; body parameters for other methods.', 'wpelevator-agent-pilot' ),
						],
					],
					'required' => [ 'method', 'route' ],
					'additionalProperties' => false,
				],
				'permission_callback' => [ $this, 'check_permission' ],
				'execute_callback' => [ $this, 'execute' ],
				'meta' => [
					'mcp' => [
						'public' => true,
						'type' => 'tool',
					],
					'annotations' => [
						'readonly' => false,
						'destructive' => true,
						'idempotent' => false,
					],
				],
			]
		);
	}

	/**
	 * Check endpoint permissions without executing its callback.
	 *
	 * @return bool|WP_Error
	 */
	public function check_permission( array $input ) {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		// MCP checks permissions before core's execute() validates the input.
		$valid = wp_get_ability( self::NAME )->validate_input( $input );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$request = $this->build_request( $input );
		$server = rest_get_server();
		$routes = [];

		// Match the same namespace ordering as the REST server.
		foreach ( $server->get_namespaces() as $namespace ) {
			if ( 0 === strpos( trailingslashit( ltrim( $request->get_route(), '/' ) ), $namespace ) ) {
				$routes = array_merge( $routes, $server->get_routes( $namespace ) );
			}
		}

		foreach ( $routes ? $routes : $server->get_routes() as $route => $handlers ) {
			if ( preg_match( '@^' . $route . '$@i', $request->get_route(), $matches ) ) {
				$request->set_url_params( array_filter( $matches, 'is_string', ARRAY_FILTER_USE_KEY ) );

				foreach ( $handlers as $handler ) {
					$method = $request->get_method();
					if ( 'HEAD' === $method && empty( $handler['methods']['HEAD'] ) ) {
						$method = 'GET';
					}

					if ( ! empty( $handler['methods'][ $method ] ) ) {
						return $this->check_handler_permission( $request, $handler );
					}
				}
			}
		}

		// Let dispatch produce the native response for an unknown route or method.
		return true;
	}

	/**
	 * Prepare endpoint parameters before invoking its permission callback.
	 *
	 * @return bool|WP_Error
	 */
	private function check_handler_permission( WP_REST_Request $request, array $handler ) {
		$request->set_attributes( $handler );
		$defaults = [];
		foreach ( $handler['args'] as $name => $options ) {
			if ( isset( $options['default'] ) ) {
				$defaults[ $name ] = $options['default'];
			}
		}
		$request->set_default_params( $defaults );

		// Invalid requests cannot execute; leave their error response to dispatch.
		if ( is_wp_error( $request->has_valid_params() ) || is_wp_error( $request->sanitize_params() ) ) {
			return true;
		}

		if ( ! empty( $handler['permission_callback'] ) ) {
			$permission = call_user_func( $handler['permission_callback'], $request );
			if ( is_wp_error( $permission ) ) {
				return $permission;
			}

			return false !== $permission && null !== $permission;
		}

		return true;
	}

	private function build_request( array $input ): WP_REST_Request {
		$request = new WP_REST_Request( $input['method'], $input['route'] );
		$params = $input['params'] ?? [];

		if ( in_array( $input['method'], [ 'GET', 'HEAD', 'DELETE' ], true ) ) {
			$request->set_query_params( $params );
		} else {
			$request->set_body_params( $params );
		}

		return $request;
	}

	public function execute( array $input ): array {
		$request = $this->build_request( $input );
		$server = rest_get_server();

		// Dispatch rechecks endpoint permissions before executing the callback.
		$response = rest_do_request( $request );

		/*
		 * rest_do_request() dispatches without serving, so a response never
		 * reaches the rest_post_dispatch filters core adds in serve_request().
		 * Two of them shape what any caller sees, and without them `_fields`
		 * and `Allow` are accepted and silently ignored - the caller pays for
		 * a full response having asked for three fields, and is told nothing.
		 * They are applied by name rather than by running the filter, so that
		 * an internal call does not also pick up whatever a site hooked there
		 * for its HTTP responses.
		 */
		$response = rest_send_allow_header( $response, $server, $request );
		$response = rest_filter_response_fields( $response, $server, $request );

		// Embedding happens during serialization rather than in a filter, and
		// core reads it from the query string, which an internal request has
		// no part in.
		$embed = $request->has_param( '_embed' )
			? rest_parse_embed_param( $request->get_param( '_embed' ) )
			: false;

		return [
			'status' => $response->get_status(),
			'headers' => $response->get_headers(),
			'data' => $server->response_to_data( $response, $embed ),
		];
	}
}
