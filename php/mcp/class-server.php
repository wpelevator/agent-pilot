<?php

namespace WPElevator\Agent_Pilot\MCP;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * An MCP server over Streamable HTTP, serving WordPress Abilities as tools.
 *
 * Dual era by design. A request that carries a modern per-request protocol
 * version is served statelessly under the current revision; an `initialize`
 * request selects the older handshake based semantics for that session. The
 * specification explicitly provides for a server that answers both, and it is
 * what lets today's clients connect without giving up the newer revision.
 *
 * Every response is `application/json`. The transport permits either that or an
 * SSE stream for a request, and since this server sends nothing a client did not
 * ask for, a plain JSON body is both compliant and a much better fit for a
 * request lifecycle that ends when PHP does.
 */
class Server {

	public const REST_NAMESPACE = 'agent-pilot/v1';

	public const REST_ROUTE = 'mcp';

	/**
	 * The current revision: no handshake, per-request version metadata, and
	 * `resultType` on every result.
	 */
	public const PROTOCOL_MODERN = '2026-07-28';

	/**
	 * The handshake based revisions, newest first. The first entry is what an
	 * `initialize` request negotiates down to when it asks for something this
	 * server does not implement.
	 */
	public const PROTOCOL_LEGACY = [ '2025-11-25', '2025-06-18', '2025-03-26' ];

	public const META_PROTOCOL_VERSION = 'io.modelcontextprotocol/protocolVersion';

	public const META_SERVER_INFO = 'io.modelcontextprotocol/serverInfo';

	public const HEADER_PROTOCOL_VERSION = 'MCP-Protocol-Version';

	private Tools $tools;

	private Authentication $authentication;

	private Sessions $sessions;

	private Settings $settings;

	private string $version;

	/**
	 * The challenge to send with the response being built, if any.
	 *
	 * Reset at the start of every request so that one can never survive into
	 * the next, which matters wherever PHP outlives a single request.
	 */
	private ?string $challenge = null;

	public function __construct( Tools $tools, Authentication $authentication, Sessions $sessions, Settings $settings, string $version = '' ) {
		$this->tools = $tools;
		$this->authentication = $authentication;
		$this->sessions = $sessions;
		$this->settings = $settings;
		$this->version = $version;
	}

	public function init(): void {
		add_action( 'rest_api_init', [ $this, 'action_register_routes' ] );
		add_action( 'admin_init', [ $this->settings, 'register' ] );
		add_action( 'rest_api_init', [ $this->settings, 'register' ] );
		add_filter( 'rest_pre_serve_request', [ $this, 'filter_serve_request' ], 10, 3 );
		// After core's own rest_post_dispatch filters, which are added later than
		// this one during rest_api_init and would otherwise have the last word.
		add_filter( 'rest_post_dispatch', [ $this, 'filter_post_dispatch' ], 11, 3 );

		$this->authentication->init();
	}

	/**
	 * Answer a body core could not parse in the protocol's own vocabulary.
	 *
	 * The REST API validates a JSON body before it dispatches, so a truncated or
	 * malformed message never reaches the handler and would otherwise come back
	 * wearing the REST API's error envelope, which no MCP client can read.
	 *
	 * @param mixed            $response The response so far.
	 * @param WP_REST_Server   $server   The server instance.
	 * @param WP_REST_Request  $request  The request.
	 *
	 * @return mixed
	 */
	public function filter_post_dispatch( $response, $server, $request ) {
		if ( ! $this->is_own_request( $request ) || ! $response instanceof WP_REST_Response ) {
			return $response;
		}

		/*
		 * The route accepts GET so that this server can answer it deliberately,
		 * but core derives `Allow` from the registered methods and would
		 * otherwise advertise GET on the very response refusing it.
		 */
		if ( 405 === $response->get_status() ) {
			$response->header( 'Allow', 'POST, DELETE' );
		}

		/*
		 * Re-assert this endpoint's own challenge. Another plugin authenticating
		 * WordPress REST requests may also add a WWW-Authenticate header on a
		 * 401, and a client sent to the wrong resource metadata document would
		 * ask for a token bound to the wrong audience and be refused with it.
		 */
		if ( isset( $this->challenge ) && in_array( $response->get_status(), [ 401, 403 ], true ) ) {
			$response->header( 'WWW-Authenticate', $this->challenge );
			$response->header( 'Access-Control-Expose-Headers', 'WWW-Authenticate' );
		}

		$data = $response->get_data();

		if ( ! is_array( $data ) || 'rest_invalid_json' !== ( $data['code'] ?? '' ) ) {
			return $response;
		}

		return $this->respond(
			Json_Rpc::error( null, Json_Rpc::PARSE_ERROR, __( 'The request body is not valid JSON.', 'wpelevator-agent-pilot' ) ),
			400
		);
	}

	/**
	 * Every protocol version this server implements, newest first.
	 *
	 * @return string[]
	 */
	public static function get_supported_versions(): array {
		return array_merge( [ self::PROTOCOL_MODERN ], self::PROTOCOL_LEGACY );
	}

	public function get_endpoint_url(): string {
		return rest_url( self::REST_NAMESPACE . '/' . self::REST_ROUTE );
	}

	public function action_register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/' . self::REST_ROUTE,
			[
				'methods' => [ WP_REST_Server::READABLE, WP_REST_Server::CREATABLE, WP_REST_Server::DELETABLE ],
				'callback' => [ $this, 'handle_request' ],
				/*
				 * Authentication happens inside the handler rather than here, so
				 * that a rejection can carry the exact status, body and
				 * `WWW-Authenticate` challenge an MCP client needs to start its
				 * OAuth discovery. A WP_Error returned from a permission callback
				 * would be reshaped into the REST API's own error envelope, which
				 * no MCP client knows how to read.
				 */
				'permission_callback' => '__return_true',
			]
		);
	}

	/**
	 * Emit the response body exactly.
	 *
	 * Core has already sent this response's headers and status by the time this
	 * filter runs, so all that is left is the body — and the reason to take it
	 * over is the one case where there must not be one: a notification is
	 * acknowledged with `202 Accepted` and no body at all, which the default
	 * serializer would render as the four bytes `null`.
	 *
	 * @param bool             $served  Whether the request has already been served.
	 * @param WP_REST_Response $result  The response.
	 * @param WP_REST_Request  $request The request.
	 */
	public function filter_serve_request( $served, $result, $request ): bool {
		if ( $served || ! $this->is_own_request( $request ) || ! $result instanceof WP_REST_Response ) {
			return (bool) $served;
		}

		$data = $result->get_data();

		if ( null !== $data ) {
			echo wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Exact serialized JSON-RPC bytes.
		}

		return true;
	}

	private function is_own_request( $request ): bool {
		return $request instanceof WP_REST_Request
			&& '/' . self::REST_NAMESPACE . '/' . self::REST_ROUTE === $request->get_route();
	}

	/**
	 * @return WP_REST_Response
	 */
	public function handle_request( WP_REST_Request $request ) {
		$this->challenge = null;

		if ( ! $this->settings->is_mcp_enabled() ) {
			return $this->respond( null, 404 );
		}

		if ( ! $this->is_origin_allowed( $request ) ) {
			// DNS rebinding protection, required of every Streamable HTTP server.
			return $this->respond( null, 403 );
		}

		if ( ! $this->tools->is_available() ) {
			return $this->respond(
				Json_Rpc::error( null, Json_Rpc::INTERNAL_ERROR, __( 'This site does not have the WordPress Abilities API.', 'wpelevator-agent-pilot' ) ),
				500
			);
		}

		$method = strtoupper( $request->get_method() );

		if ( 'GET' === $method ) {
			/*
			 * A client may open a GET stream to receive server initiated
			 * messages. This server never sends any, and the spec's answer for
			 * that is 405 rather than an empty stream.
			 */
			return $this->respond( null, 405, [ 'Allow' => 'POST, DELETE' ] );
		}

		if ( 'DELETE' === $method ) {
			return $this->handle_delete( $request );
		}

		return $this->handle_post( $request );
	}

	private function handle_delete( WP_REST_Request $request ): WP_REST_Response {
		$session_id = (string) $request->get_header( Sessions::HEADER );

		if ( '' === $session_id ) {
			return $this->respond( null, 400 );
		}

		$this->sessions->delete( $session_id );

		return $this->respond( null, 204 );
	}

	private function handle_post( WP_REST_Request $request ): WP_REST_Response {
		$message = Json_Rpc::parse( (string) $request->get_body() );

		if ( null === $message ) {
			return $this->respond(
				Json_Rpc::error( null, Json_Rpc::PARSE_ERROR, __( 'The request body is not a single JSON-RPC message.', 'wpelevator-agent-pilot' ) ),
				400
			);
		}

		if ( ! Json_Rpc::is_valid( $message ) ) {
			return $this->respond(
				Json_Rpc::error( Json_Rpc::get_id( $message ), Json_Rpc::INVALID_REQUEST, __( 'The request is not a valid JSON-RPC 2.0 message.', 'wpelevator-agent-pilot' ) ),
				400
			);
		}

		$id = Json_Rpc::get_id( $message );
		$method = Json_Rpc::get_method( $message );
		$params = Json_Rpc::get_params( $message );
		$requested_version = $this->get_requested_version( $message, $request );
		$is_modern = self::PROTOCOL_MODERN === $requested_version;

		/*
		 * An unknown version is only an error for a client that declared one.
		 * A legacy `initialize` instead negotiates: the server answers with a
		 * version it does support and the client decides whether to continue.
		 */
		if ( '' !== $requested_version && ! in_array( $requested_version, self::get_supported_versions(), true ) && 'initialize' !== $method ) {
			return $this->respond(
				Json_Rpc::error(
					$id,
					Json_Rpc::UNSUPPORTED_PROTOCOL_VERSION,
					__( 'Unsupported protocol version', 'wpelevator-agent-pilot' ),
					[
						'supported' => self::get_supported_versions(),
						'requested' => $requested_version,
					]
				),
				400
			);
		}

		$session_id = (string) $request->get_header( Sessions::HEADER );

		// A session the server has forgotten must be reported so the client starts a new one.
		if ( '' !== $session_id && ! $this->sessions->exists( $session_id ) ) {
			return $this->respond( null, 404 );
		}

		$identity = $this->authentication->authenticate();

		if ( is_wp_error( $identity ) ) {
			return $this->unauthorized( $identity );
		}

		if ( Json_Rpc::is_notification( $message ) ) {
			// Nothing this server does depends on a client notification yet.
			return $this->respond( null, 202 );
		}

		$result = $this->dispatch( $method, $params, $identity, $is_modern );

		if ( is_wp_error( $result ) ) {
			return $this->from_wp_error( $id, $result );
		}

		$headers = [];

		if ( 'initialize' === $method ) {
			$headers[ Sessions::HEADER ] = $this->sessions->create(
				(string) ( $result['protocolVersion'] ?? '' ),
				$identity->get_user_id()
			);
		}

		if ( $is_modern ) {
			// The current revision tags every result with how complete it is.
			$result = array_merge( [ 'resultType' => 'complete' ], $result );
		}

		return $this->respond( Json_Rpc::result( $id, $result ), 200, $headers );
	}

	/**
	 * @return array|WP_Error
	 */
	private function dispatch( string $method, array $params, Identity $identity, bool $is_modern ) {
		switch ( $method ) {
			case 'initialize':
				return $this->handle_initialize( $params );

			case 'server/discover':
				return $this->handle_discover();

			case 'ping':
				return [];

			case 'tools/list':
				return [ 'tools' => $this->tools->get_tools( $identity ) ];

			case 'tools/call':
				return $this->handle_tools_call( $params, $identity );
		}

		return new WP_Error(
			'agent_pilot_mcp_method_not_found',
			sprintf(
				/* translators: %s: the requested JSON-RPC method name. */
				__( 'Method not found: %s', 'wpelevator-agent-pilot' ),
				$method
			),
			[ 'code' => Json_Rpc::METHOD_NOT_FOUND ]
		);
	}

	private function handle_initialize( array $params ): array {
		$requested = (string) ( $params['protocolVersion'] ?? '' );

		$version = in_array( $requested, self::PROTOCOL_LEGACY, true )
			? $requested
			: self::PROTOCOL_LEGACY[0];

		return [
			'protocolVersion' => $version,
			'capabilities' => $this->get_capabilities(),
			'serverInfo' => $this->get_server_info(),
			'instructions' => $this->get_instructions(),
		];
	}

	private function handle_discover(): array {
		return [
			'supportedVersions' => self::get_supported_versions(),
			'capabilities' => $this->get_capabilities(),
			'instructions' => $this->get_instructions(),
			'_meta' => [
				self::META_SERVER_INFO => $this->get_server_info(),
			],
		];
	}

	/**
	 * @return array|WP_Error
	 */
	private function handle_tools_call( array $params, Identity $identity ) {
		$name = (string) ( $params['name'] ?? '' );

		if ( '' === $name ) {
			return new WP_Error(
				'agent_pilot_mcp_missing_tool_name',
				__( 'A tool name is required.', 'wpelevator-agent-pilot' ),
				[ 'code' => Json_Rpc::INVALID_PARAMS ]
			);
		}

		$arguments = $params['arguments'] ?? [];

		return $this->tools->call( $name, is_array( $arguments ) ? $arguments : [], $identity );
	}

	private function get_capabilities(): array {
		return [
			'tools' => [
				// The tool list changes only when a site's plugins change, and
				// this server holds no stream to announce it on.
				'listChanged' => false,
			],
		];
	}

	private function get_server_info(): array {
		$name = trim( (string) get_bloginfo( 'name' ) );

		$info = [
			'name' => '' !== $name ? $name : __( 'WordPress', 'wpelevator-agent-pilot' ),
			'version' => $this->version,
		];

		/**
		 * Override the advertised MCP server identity.
		 *
		 * @param array $info The `name` and `version` this server reports.
		 */
		return (array) apply_filters( 'agent_pilot__mcp_server_info', $info );
	}

	private function get_instructions(): string {
		$instructions = sprintf(
			/* translators: %s: the site name. */
			__( 'Tools on this server are WordPress Abilities published by %s. Each tool runs with the permissions of the authenticated WordPress user, so a call may be refused even when the tool is listed.', 'wpelevator-agent-pilot' ),
			get_bloginfo( 'name' )
		);

		/**
		 * Override the natural language guidance sent to MCP clients.
		 *
		 * @param string $instructions The advertised instructions.
		 */
		return (string) apply_filters( 'agent_pilot__mcp_instructions', $instructions );
	}

	/**
	 * The protocol version a request declared, in either era's spelling.
	 *
	 * Returns an empty string when the client declared nothing, which the spec
	 * says to read as the oldest handshake based revision.
	 */
	public function get_requested_version( array $message, WP_REST_Request $request ): string {
		$params = Json_Rpc::get_params( $message );

		if ( ! empty( $params['_meta'][ self::META_PROTOCOL_VERSION ] ) ) {
			return (string) $params['_meta'][ self::META_PROTOCOL_VERSION ];
		}

		$header = (string) $request->get_header( self::HEADER_PROTOCOL_VERSION );

		if ( '' !== $header ) {
			return $header;
		}

		/*
		 * `server/discover` exists only in the current revision, so a client
		 * calling it without saying so is still unambiguously modern.
		 */
		if ( 'server/discover' === Json_Rpc::get_method( $message ) ) {
			return self::PROTOCOL_MODERN;
		}

		return '';
	}

	/**
	 * Whether a browser origin may call this endpoint.
	 *
	 * A request with no `Origin` header did not come from a browser and is left
	 * to authentication to judge. One that does carry an origin must match the
	 * site's own or an explicitly allowed one, which is what stops a page the
	 * user happens to be visiting from driving a local MCP server.
	 */
	public function is_origin_allowed( WP_REST_Request $request ): bool {
		$origin = (string) $request->get_header( 'origin' );
		$is_allowed = true;

		if ( '' !== $origin ) {
			$origin = untrailingslashit( strtolower( $origin ) );

			$allowed = array_map(
				fn ( string $url ): string => untrailingslashit( strtolower( $this->get_origin( $url ) ) ),
				[ home_url(), site_url() ]
			);

			/**
			 * Filter the browser origins allowed to call the MCP endpoint.
			 *
			 * @param string[] $allowed The allowed origins.
			 * @param string   $origin  The origin of the current request.
			 */
			$allowed = (array) apply_filters( 'agent_pilot__mcp_allowed_origins', $allowed, $origin );
			$is_allowed = in_array( $origin, $allowed, true );
		}

		return $is_allowed;
	}

	private function get_origin( string $url ): string {
		$parts = wp_parse_url( $url );

		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return $url;
		}

		return sprintf(
			'%s://%s%s',
			$parts['scheme'],
			$parts['host'],
			isset( $parts['port'] ) ? ':' . $parts['port'] : ''
		);
	}

	/**
	 * Turn an authentication failure into the HTTP level challenge an MCP
	 * client acts on.
	 *
	 * This is deliberately not a JSON-RPC error: the MCP authorization flow is
	 * driven by the HTTP status and the `WWW-Authenticate` header, and a client
	 * that receives a 200 with an error body has nothing to discover from.
	 */
	private function unauthorized( WP_Error $error ): WP_REST_Response {
		$data = $error->get_error_data();
		$headers = [];

		if ( ! empty( $data['www_authenticate'] ) ) {
			$this->challenge = (string) $data['www_authenticate'];

			$headers['WWW-Authenticate'] = $this->challenge;
			$headers['Access-Control-Expose-Headers'] = 'WWW-Authenticate';
		}

		return $this->respond(
			Json_Rpc::error( null, Json_Rpc::INVALID_REQUEST, $error->get_error_message() ),
			(int) ( $data['status'] ?? 401 ),
			$headers
		);
	}

	/**
	 * @param string|int|float|null $id
	 */
	private function from_wp_error( $id, WP_Error $error ): WP_REST_Response {
		$data = $error->get_error_data();
		$status = (int) ( $data['status'] ?? 400 );
		$code = (int) ( $data['code'] ?? Json_Rpc::INVALID_PARAMS );

		if ( 403 === $status ) {
			$this->challenge = $this->authentication->get_challenge( (array) ( $data['scope'] ?? [] ) );

			return $this->respond(
				Json_Rpc::error( $id, Json_Rpc::INVALID_REQUEST, $error->get_error_message() ),
				403,
				[ 'WWW-Authenticate' => $this->challenge ]
			);
		}

		/*
		 * Everything else is a protocol error, which travels in a 200 response:
		 * the HTTP layer delivered the message fine, it was the JSON-RPC call
		 * that failed.
		 */
		return $this->respond( Json_Rpc::error( $id, $code, $error->get_error_message() ), 200 );
	}

	private function respond( ?array $data, int $status, array $headers = [] ): WP_REST_Response {
		$response = new WP_REST_Response( $data, $status );

		$response->header( 'Content-Type', 'application/json; charset=' . get_option( 'blog_charset' ) );
		$response->header( 'Cache-Control', 'no-store' );

		foreach ( $headers as $name => $value ) {
			$response->header( $name, $value );
		}

		return $response;
	}
}
