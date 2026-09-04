<?php

namespace WPElevator\Agent_Pilot\MCP;

use WP_Error;

/**
 * Authenticates MCP requests, preferring an OAuth Pilot bearer token and
 * falling back to whatever WordPress itself already resolved.
 *
 * The integration is one directional and soft. OAuth Pilot knows nothing about
 * MCP: this class registers the MCP endpoint as a protected resource through
 * OAuth Pilot's own `oauth_pilot__register_resources` action, and calls the
 * public `Bearer_Validator` API from there on. When OAuth Pilot is not
 * installed, every one of those calls is skipped and the server still works for
 * a signed-in user or an Application Password.
 */
class Authentication {

	/**
	 * The scopes this MCP server registers and enforces at the ability boundary.
	 * A site that wants its own vocabulary can remap them through
	 * `agent_pilot__mcp_tool_scopes`.
	 */
	public const SCOPE_READ = 'wp:read';

	public const SCOPE_WRITE = 'wp:write';

	public function init(): void {
		add_action( 'oauth_pilot__register_scopes', [ $this, 'action_register_scopes' ] );
		add_action( 'oauth_pilot__register_resources', [ $this, 'action_register_resource' ] );
	}

	/**
	 * Register the scopes this MCP server can enforce at the ability boundary.
	 *
	 * @param \WPElevator\OAuth_Pilot\Resources\Scopes $scopes The registry.
	 */
	public function action_register_scopes( $scopes ): void {
		if ( ! is_object( $scopes ) || ! method_exists( $scopes, 'register' ) ) {
			return;
		}

		$scopes->register(
			[
				'name' => self::SCOPE_READ,
				'label' => __( 'Read MCP tools', 'wpelevator-agent-pilot' ),
				'description' => __( 'Call read-only tools available to your WordPress account.', 'wpelevator-agent-pilot' ),
				'user_can_grant' => fn ( int $user_id ): bool => user_can( $user_id, 'read' ),
			]
		);

		$scopes->register(
			[
				'name' => self::SCOPE_WRITE,
				'label' => __( 'Use MCP tools', 'wpelevator-agent-pilot' ),
				'description' => __( 'Call read and write tools available to your WordPress account.', 'wpelevator-agent-pilot' ),
				'implies' => [ self::SCOPE_READ ],
				'user_can_grant' => fn ( int $user_id ): bool => user_can( $user_id, 'edit_posts' ),
			]
		);
	}

	/**
	 * Whether OAuth Pilot is installed and exposes the API this class needs.
	 */
	public function is_oauth_available(): bool {
		return function_exists( 'WPElevator\OAuth_Pilot\plugin' );
	}

	/**
	 * The canonical audience for tokens minted to call this server.
	 */
	public function get_resource_uri(): string {
		return rest_url( Server::REST_NAMESPACE . '/' . Server::REST_ROUTE );
	}

	/**
	 * Register the MCP endpoint as its own OAuth audience.
	 *
	 * Registering a resource nested under `wp-json` rather than reusing the
	 * WordPress one is what keeps the two apart: OAuth Pilot matches an
	 * incoming URL to the deepest registered path, so a token minted for MCP is
	 * not accepted on `/wp/v2/posts`, and OAuth Pilot's own REST authentication
	 * steps aside here because the matched resource is not the WordPress one.
	 *
	 * `requires_resource` is true because the MCP authorization spec requires
	 * clients to send an RFC 8707 `resource` parameter. Leaving it false would
	 * let this resource become the site's default audience purely by
	 * registration order, and quietly hand MCP tokens to clients that meant to
	 * call the REST API.
	 *
	 * @param \WPElevator\OAuth_Pilot\Resources\Protected_Resources $resources The registry.
	 */
	public function action_register_resource( $resources ): void {
		if ( ! is_object( $resources ) || ! method_exists( $resources, 'register' ) ) {
			return;
		}

		$resources->register(
			[
				'uri' => $this->get_resource_uri(),
				'name' => __( 'Agent Pilot MCP server', 'wpelevator-agent-pilot' ),
				'scopes' => [ self::SCOPE_READ, self::SCOPE_WRITE ],
				'defaults' => [ self::SCOPE_READ ],
				'requires_resource' => true,
			]
		);
	}

	/**
	 * Resolve the caller, enforcing the scopes the request needs.
	 *
	 * @param string[] $required_scopes
	 *
	 * @return Identity|WP_Error The caller, or an error carrying the HTTP status
	 *                           and the `WWW-Authenticate` challenge to send.
	 */
	public function authenticate( array $required_scopes = [] ) {
		if ( $this->is_oauth_available() ) {
			$validator = \WPElevator\OAuth_Pilot\plugin()->get_validator();

			if ( $validator->has_bearer_credential() ) {
				$context = $validator->validate_request( $this->get_resource_uri(), $required_scopes );

				if ( is_wp_error( $context ) ) {
					return $context;
				}

				// Only now, after the token fully validated, does a user exist.
				wp_set_current_user( $context->get_user_id() );

				return Identity::from_token(
					$context->get_user_id(),
					$context->get_scopes(),
					$context->get_client_id()
				);
			}
		}

		/*
		 * No token. WordPress may still have authenticated this request through
		 * a cookie or an Application Password before the route was dispatched,
		 * which is how a signed-in administrator or a site without OAuth Pilot
		 * reaches the server.
		 */
		$user_id = get_current_user_id();

		if ( $user_id > 0 ) {
			return Identity::from_wordpress_user( $user_id );
		}

		return new WP_Error(
			'agent_pilot_mcp_unauthorized',
			__( 'Authentication is required to use this MCP server.', 'wpelevator-agent-pilot' ),
			[
				'status' => 401,
				'www_authenticate' => $this->get_challenge( $required_scopes ),
			]
		);
	}

	/**
	 * The RFC 6750 challenge to send with a 401.
	 *
	 * When OAuth Pilot is present this carries the RFC 9728 `resource_metadata`
	 * pointer, which is the single header that lets an MCP client bootstrap
	 * from nothing but the site URL: it finds the authorization server, registers
	 * itself, and runs the consent flow without any of it being configured.
	 *
	 * @param string[] $required_scopes
	 * @param string|null $error_code OAuth bearer error code, when applicable.
	 * @param string $description Human-readable error description.
	 */
	public function get_challenge( array $required_scopes = [], ?string $error_code = null, string $description = '' ): string {
		if ( ! $this->is_oauth_available() ) {
			return 'Bearer';
		}

		return \WPElevator\OAuth_Pilot\plugin()->get_validator()->get_challenge(
			$this->get_resource_uri(),
			$error_code,
			$description,
			$required_scopes
		);
	}
}
