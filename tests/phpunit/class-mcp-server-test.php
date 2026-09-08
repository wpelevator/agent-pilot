<?php

namespace WPElevator\Agent_Pilot_Tests;

use WP_REST_Request;
use WPElevator\Agent_Pilot\MCP\Json_Rpc;
use WPElevator\Agent_Pilot\MCP\Server;
use WPElevator\Agent_Pilot\MCP\Sessions;

require_once __DIR__ . '/class-mcp-test-case.php';

class MCP_Server_Test extends MCP_Test_Case {

	private string $route;

	public function set_up() {
		parent::set_up();

		$this->route = '/' . Server::REST_NAMESPACE . '/' . Server::REST_ROUTE;

		// Every request below is authenticated as a signed-in administrator
		// unless the test says otherwise; authentication has its own file.
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	public function test_endpoint_is_not_served_until_it_is_enabled() {
		remove_filter( 'agent_pilot__mcp_enabled', '__return_true' );

		$response = $this->post( $this->message( 'ping' ) );

		$this->assertSame( 404, $response->get_status(), 'The MCP server is opt-in, so a site that never enabled it should not answer at all.' );
	}

	public function test_get_is_refused_because_no_stream_is_offered() {
		$response = $this->serve( new WP_REST_Request( 'GET', $this->route ) );

		$this->assertSame( 405, $response->get_status(), 'A client may open a GET stream for server initiated messages, and a server that sends none must answer 405 rather than an empty stream.' );
		$this->assertSame( 'POST, DELETE', $response->get_headers()['Allow'], 'The 405 should tell the client which methods the endpoint does accept.' );
	}

	public function test_a_foreign_browser_origin_is_refused() {
		$response = $this->post( $this->message( 'ping' ), [ 'origin' => 'https://evil.example' ] );

		$this->assertSame( 403, $response->get_status(), 'Origin validation is what stops a page the user happens to be visiting from driving this server through their browser.' );
	}

	public function test_an_unauthenticated_foreign_origin_is_challenged_before_it_is_refused() {
		wp_set_current_user( 0 );

		$response = $this->post( $this->message( 'ping' ), [ 'origin' => 'https://evil.example' ] );

		$this->assertSame(
			401,
			$response->get_status(),
			'A client that has never authenticated must receive the OAuth challenge rather than a bare 403 it cannot act on.'
		);
		$this->assertArrayHasKey(
			'WWW-Authenticate',
			$response->get_headers(),
			'The challenge is what lets that client discover the authorization server and come back with a token.'
		);
	}

	public function test_the_site_own_origin_is_allowed() {
		$response = $this->post( $this->message( 'ping' ), [ 'origin' => home_url() ] );

		$this->assertSame( 200, $response->get_status(), 'A request from the site itself should be allowed.' );
	}

	public function test_the_allowed_origins_filter_can_allow_another_origin() {
		$filter = fn ( array $allowed ): array => array_merge( $allowed, [ 'https://client.example' ] );

		add_filter( 'agent_pilot__mcp_allowed_origins', $filter );
		$response = $this->post( $this->message( 'ping' ), [ 'origin' => 'https://client.example' ] );
		remove_filter( 'agent_pilot__mcp_allowed_origins', $filter );

		$this->assertSame( 200, $response->get_status(), 'The filter should allow an integrator to add a browser origin without an admin setting.' );
	}

	public function test_a_null_browser_origin_is_refused() {
		$response = $this->post( $this->message( 'ping' ), [ 'origin' => 'null' ] );

		$this->assertSame( 403, $response->get_status(), 'A present but opaque browser origin should not be treated like an absent Origin header.' );
	}

	public function test_a_request_without_an_origin_is_allowed() {
		$response = $this->post( $this->message( 'ping' ) );

		$this->assertSame( 200, $response->get_status(), 'A request with no Origin header did not come from a browser, so it is left to authentication to judge.' );
	}

	public function test_a_malformed_body_is_a_parse_error() {
		$request = new WP_REST_Request( 'POST', $this->route );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( '{not json' );

		$response = $this->serve( $request );

		$this->assertSame( Json_Rpc::PARSE_ERROR, $response->get_data()['error']['code'], 'A body that is not JSON should be reported as a JSON-RPC parse error.' );
	}

	public function test_a_batch_is_rejected() {
		$request = new WP_REST_Request( 'POST', $this->route );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( (string) wp_json_encode( [ $this->message( 'ping' ) ] ) );

		$response = $this->serve( $request );

		$this->assertSame( Json_Rpc::PARSE_ERROR, $response->get_data()['error']['code'], 'MCP removed JSON-RPC batching, so a request body must be exactly one message.' );
	}

	public function test_a_message_without_jsonrpc_version_is_invalid() {
		$response = $this->post(
			[
				'method' => 'ping',
				'id' => 1,
			]
		);

		$this->assertSame( Json_Rpc::INVALID_REQUEST, $response->get_data()['error']['code'], 'A message missing the JSON-RPC version is not a valid request.' );
	}

	public function test_an_unknown_method_is_reported_as_method_not_found() {
		$response = $this->post( $this->message( 'tools/nonexistent' ) );

		$this->assertSame( 200, $response->get_status(), 'A protocol error travels in a 200 response, because the HTTP layer delivered the message fine.' );
		$this->assertSame( Json_Rpc::METHOD_NOT_FOUND, $response->get_data()['error']['code'], 'An unimplemented method should be reported as method not found.' );
	}

	public function test_a_notification_is_acknowledged_with_no_body() {
		$response = $this->post( $this->message( 'notifications/initialized', [], null ) );

		$this->assertSame( 202, $response->get_status(), 'A JSON-RPC notification must be acknowledged with 202 Accepted.' );
		$this->assertNull( $response->get_data(), 'A 202 acknowledgement must carry no body at all.' );
	}

	public function test_ping_answers_with_an_empty_result() {
		$response = $this->post( $this->message( 'ping', [], 'ping-1' ) );

		$this->assertSame( 'ping-1', $response->get_data()['id'], 'The response must echo the request id, including a string one.' );
		$this->assertSame( [], $response->get_data()['result'], 'A ping answers with an empty result.' );
	}

	public function test_initialize_negotiates_a_supported_legacy_version() {
		$response = $this->post( $this->message( 'initialize', [ 'protocolVersion' => '2025-06-18' ] ) );

		$result = $response->get_data()['result'];

		$this->assertSame( '2025-06-18', $result['protocolVersion'], 'A client asking for a supported legacy revision should get exactly that one back.' );
		$this->assertFalse( $result['capabilities']['tools']['listChanged'], 'This server holds no stream to announce tool changes on, so listChanged is false.' );
		$this->assertNotEmpty( $result['serverInfo']['name'], 'The server should identify itself.' );
	}

	public function test_initialize_negotiates_down_from_an_unknown_version() {
		$response = $this->post( $this->message( 'initialize', [ 'protocolVersion' => '1900-01-01' ] ) );

		$this->assertSame(
			Server::PROTOCOL_LEGACY[0],
			$response->get_data()['result']['protocolVersion'],
			'An initialize asking for an unknown version negotiates rather than errors: the server answers with one it does support.'
		);
	}

	public function test_initialize_issues_a_session_id() {
		$response = $this->post( $this->message( 'initialize', [ 'protocolVersion' => '2025-06-18' ] ) );

		$session_id = $response->get_headers()[ Sessions::HEADER ] ?? '';

		$this->assertNotEmpty( $session_id, 'A legacy initialize should hand the client a session id.' );
		$this->assertTrue( ( new Sessions() )->exists( $session_id ), 'The issued session should be resolvable on the next request.' );
	}

	public function test_a_known_session_is_accepted() {
		$session_id = ( new Sessions() )->create( '2025-06-18', 1 );

		$response = $this->post( $this->message( 'ping' ), [ Sessions::HEADER => $session_id ] );

		$this->assertSame( 200, $response->get_status(), 'A request carrying a live session id should be served normally.' );
	}

	public function test_an_unknown_session_is_reported_as_gone() {
		$response = $this->post( $this->message( 'ping' ), [ Sessions::HEADER => 'f47ac10b-58cc-4372-a567-0e02b2c3d479' ] );

		$this->assertSame( 404, $response->get_status(), 'A session the server has forgotten must be reported as 404 so the client knows to start a new one.' );
	}

	public function test_a_session_can_be_terminated() {
		$sessions = new Sessions();
		$session_id = $sessions->create( '2025-06-18', 1 );

		$request = new WP_REST_Request( 'DELETE', $this->route );
		$request->set_header( Sessions::HEADER, $session_id );

		$response = $this->serve( $request );

		$this->assertSame( 204, $response->get_status(), 'A client leaving should be able to terminate its session.' );
		$this->assertFalse( $sessions->exists( $session_id ), 'A terminated session should no longer resolve.' );
	}

	public function test_server_discover_is_answered_without_a_handshake() {
		$response = $this->post( $this->message( 'server/discover' ) );

		$result = $response->get_data()['result'];

		$this->assertSame( 'complete', $result['resultType'], 'The current revision tags every result with how complete it is.' );
		$this->assertContains( Server::PROTOCOL_MODERN, $result['supportedVersions'], 'Discovery should advertise the current revision.' );
		$this->assertContains( '2025-06-18', $result['supportedVersions'], 'A dual era server should advertise the legacy revisions it also speaks.' );
		$this->assertNotEmpty( $result['_meta'][ Server::META_SERVER_INFO ]['name'], 'Server identity travels in _meta in the current revision.' );
	}

	public function test_server_discover_is_recognised_as_modern_without_being_told() {
		$response = $this->post( $this->message( 'server/discover' ) );

		$this->assertArrayHasKey(
			'resultType',
			$response->get_data()['result'],
			'server/discover exists only in the current revision, so a client calling it without declaring a version is still unambiguously modern.'
		);
	}

	public function test_a_modern_request_is_tagged_complete() {
		$response = $this->post( $this->as_modern( $this->message( 'tools/list' ) ) );

		$this->assertSame( 'complete', $response->get_data()['result']['resultType'], 'A request declaring the current revision should get a result tagged with resultType.' );
	}

	public function test_a_legacy_request_is_not_tagged_complete() {
		$response = $this->post( $this->message( 'tools/list' ), [ Server::HEADER_PROTOCOL_VERSION => '2025-06-18' ] );

		$this->assertArrayNotHasKey( 'resultType', $response->get_data()['result'], 'A legacy revision has no resultType, and sending one would be an unexpected field.' );
	}

	public function test_an_unsupported_protocol_version_is_rejected() {
		$response = $this->post( $this->message( 'tools/list' ), [ Server::HEADER_PROTOCOL_VERSION => '1900-01-01' ] );

		$error = $response->get_data()['error'];

		$this->assertSame( Json_Rpc::UNSUPPORTED_PROTOCOL_VERSION, $error['code'], 'A declared version the server does not implement is rejected with the MCP specific error code.' );
		$this->assertSame( '1900-01-01', $error['data']['requested'], 'The error should name the version that was requested.' );
		$this->assertContains( Server::PROTOCOL_MODERN, $error['data']['supported'], 'The error must list the versions the client can retry with.' );
	}

	public function test_tools_list_returns_the_exposed_abilities() {
		$this->register_ability( 'agent-pilot-test/listed' );

		$names = wp_list_pluck( $this->post( $this->message( 'tools/list' ) )->get_data()['result']['tools'], 'name' );

		$this->assertContains( 'agent-pilot-test-listed', $names, 'An opted-in ability should appear in tools/list.' );
	}

	public function test_tools_call_executes_the_ability() {
		$this->register_ability(
			'agent-pilot-test/called',
			[
				'input_schema' => [
					'type' => 'object',
					'properties' => [ 'name' => [ 'type' => 'string' ] ],
				],
				'execute_callback' => fn( $input ) => 'Hello ' . ( $input['name'] ?? '' ),
			]
		);

		$response = $this->post(
			$this->message(
				'tools/call',
				[
					'name' => 'agent-pilot-test-called',
					'arguments' => [ 'name' => 'Ada' ],
				]
			)
		);

		$result = $response->get_data()['result'];

		$this->assertFalse( $result['isError'], 'A successful call should not be flagged as an error.' );
		$this->assertSame( 'Hello Ada', $result['content'][0]['text'], 'The ability result should reach the client.' );
	}

	public function test_tools_call_without_a_name_is_an_invalid_params_error() {
		$response = $this->post( $this->message( 'tools/call', [ 'arguments' => [] ] ) );

		$this->assertSame( Json_Rpc::INVALID_PARAMS, $response->get_data()['error']['code'], 'A tools/call with no tool name is a malformed request rather than a tool failure.' );
	}

	public function test_calling_an_unknown_tool_is_a_protocol_error() {
		$response = $this->post(
			$this->message(
				'tools/call',
				[ 'name' => 'agent-pilot-test-missing' ]
			)
		);

		$this->assertArrayHasKey( 'error', $response->get_data(), 'An unknown tool is a protocol error, which models are unlikely to recover from by retrying.' );
	}
}
