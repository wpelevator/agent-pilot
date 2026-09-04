<?php

namespace WPElevator\Agent_Pilot_Tests;

use WPElevator\Agent_Pilot\MCP\Authentication;
use WPElevator\OAuth_Pilot\Token\Token;
use function WPElevator\OAuth_Pilot\plugin as oauth_pilot;

require_once __DIR__ . '/class-mcp-test-case.php';

/**
 * The OAuth Pilot integration.
 *
 * Nothing here is configured on either side: Agent Pilot registers the MCP
 * endpoint through OAuth Pilot's own resource action, and OAuth Pilot never
 * learns what MCP is.
 */
class MCP_Authentication_Test extends MCP_Test_Case {

	private Authentication $authentication;

	private string $resource_uri;

	public function set_up() {
		parent::set_up();

		if ( ! function_exists( 'WPElevator\OAuth_Pilot\plugin' ) ) {
			$this->markTestSkipped( 'OAuth Pilot is not active.' );
		}

		$this->authentication = new Authentication();

		oauth_pilot()->get_schema()->install_if_needed();

		// The registries cache after their first read, so they have to be
		// rebuilt for this test's registrations to be seen.
		oauth_pilot()->get_scopes()->reset();
		oauth_pilot()->get_resources()->reset();

		$this->resource_uri = oauth_pilot()->get_urls()->normalize_url( $this->authentication->get_resource_uri() );

		wp_set_current_user( 0 );
	}

	public function tear_down() {
		unset( $_SERVER['HTTP_AUTHORIZATION'] );

		oauth_pilot()->get_scopes()->reset();
		oauth_pilot()->get_resources()->reset();

		parent::tear_down();
	}

	/**
	 * Mint an access token bound to the MCP endpoint.
	 *
	 * @param string[] $scopes
	 */
	private function issue_token( array $scopes = [ Authentication::SCOPE_READ ], array $args = [] ): array {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );

		$issued = oauth_pilot()->get_tokens()->issue(
			array_merge(
				[
					'token_type' => Token::TYPE_ACCESS,
					'client_id' => $this->create_client()->get_client_id(),
					'user_id' => $user_id,
					'scopes' => $scopes,
					'resource' => $this->resource_uri,
				],
				$args
			)
		);

		return [
			'value' => $issued['value'],
			'user_id' => $user_id,
		];
	}

	private function create_client() {
		return oauth_pilot()->get_clients()->create(
			[
				'name' => 'Test Agent',
				'client_type' => \WPElevator\OAuth_Pilot\Client\Client::TYPE_PUBLIC,
				'token_endpoint_auth_method' => \WPElevator\OAuth_Pilot\Client\Client::AUTH_NONE,
				'redirect_uris' => [ 'https://claude.example.com/api/mcp/auth_callback' ],
				'grant_types' => [ \WPElevator\OAuth_Pilot\Client\Client::GRANT_AUTHORIZATION_CODE ],
				'source' => \WPElevator\OAuth_Pilot\Client\Client::SOURCE_DYNAMIC,
			]
		);
	}

	private function set_bearer( string $token ): void {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
	}

	public function test_agent_pilot_registers_the_scopes_it_enforces() {
		$scopes = oauth_pilot()->get_scopes();

		$this->assertNotNull(
			$scopes->get( Authentication::SCOPE_READ ),
			'Agent Pilot should own the read scope because it classifies and filters read-only abilities.'
		);
		$this->assertSame(
			[ Authentication::SCOPE_READ ],
			$scopes->get( Authentication::SCOPE_WRITE )->get_implies(),
			'The write scope should include read access so a write-capable MCP client can use every tool.'
		);
	}

	public function test_the_mcp_endpoint_registers_itself_as_a_protected_resource() {
		$resource = oauth_pilot()->get_resources()->get( $this->resource_uri );

		$this->assertNotNull( $resource, 'Agent Pilot should register the MCP endpoint as its own OAuth audience with no configuration.' );
		$this->assertSame(
			[ Authentication::SCOPE_READ, Authentication::SCOPE_WRITE ],
			$resource->get_scopes(),
			'The MCP resource should offer the read and write scopes Agent Pilot can enforce per ability.'
		);
		$this->assertTrue(
			$resource->requires_resource(),
			'The MCP resource must demand an explicit resource parameter, so that it never becomes the site default audience and quietly receives tokens meant for the REST API.'
		);
	}

	public function test_the_mcp_resource_is_distinct_from_the_wordpress_rest_api() {
		$resources = oauth_pilot()->get_resources();

		$this->assertNotSame(
			$resources->get( rest_url() )->get_uri(),
			$resources->match_url( $this->authentication->get_resource_uri() )->get_uri(),
			'A URL under the MCP endpoint must resolve to the MCP resource rather than to the WordPress REST API, which is what keeps the two audiences apart.'
		);
	}

	public function test_a_tokenless_request_is_challenged_with_the_resource_metadata_pointer() {
		$response = $this->post( $this->message( 'tools/list' ) );

		$this->assertSame( 401, $response->get_status(), 'An unauthenticated MCP request must be refused.' );

		$challenge = $response->get_headers()['WWW-Authenticate'] ?? '';

		$this->assertStringContainsString( 'Bearer', $challenge, 'The refusal must carry a bearer challenge.' );
		$this->assertStringContainsString(
			'resource_metadata=',
			$challenge,
			'The resource_metadata pointer is the single header that lets an MCP client bootstrap from nothing but the site URL.'
		);
		$this->assertStringContainsString(
			oauth_pilot()->get_urls()->get_protected_resource_metadata_url( $this->resource_uri ),
			$challenge,
			'The challenge should point at this endpoint\'s own RFC 9728 metadata document.'
		);
	}

	public function test_a_valid_token_authenticates_the_request_as_its_user() {
		$this->register_ability(
			'agent-pilot-test/whoami',
			[
				'meta' => [
					'mcp' => [ 'public' => true ],
					'annotations' => [ 'readonly' => true ],
				],
				'execute_callback' => fn() => (string) get_current_user_id(),
			]
		);

		$token = $this->issue_token();
		$this->set_bearer( $token['value'] );

		$response = $this->post( $this->message( 'tools/call', [ 'name' => 'agent-pilot-test.whoami' ] ) );

		$this->assertSame( 200, $response->get_status(), 'A live token bound to this resource should be accepted.' );
		$this->assertSame(
			(string) $token['user_id'],
			$response->get_data()['result']['content'][0]['text'],
			'The ability must run as the user the token represents, so its own capability checks apply to that user.'
		);
	}

	public function test_a_token_minted_for_the_rest_api_is_refused() {
		$token = $this->issue_token(
			[ Authentication::SCOPE_READ ],
			[ 'resource' => oauth_pilot()->get_urls()->normalize_url( rest_url() ) ]
		);

		$this->set_bearer( $token['value'] );

		$response = $this->post( $this->message( 'tools/list' ) );

		$this->assertSame(
			401,
			$response->get_status(),
			'A token minted for the WordPress REST API must not open the MCP endpoint nested under it.'
		);
	}

	public function test_an_invalid_token_is_refused() {
		$this->set_bearer( 'not-a-real-token' );

		$response = $this->post( $this->message( 'tools/list' ) );

		$this->assertSame( 401, $response->get_status(), 'An unknown credential must be refused rather than falling through to an anonymous request.' );
	}

	public function test_a_read_token_lists_only_the_read_tools() {
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

		$this->set_bearer( $this->issue_token( [ Authentication::SCOPE_READ ] )['value'] );

		$names = wp_list_pluck( $this->post( $this->message( 'tools/list' ) )->get_data()['result']['tools'], 'name' );

		$this->assertContains( 'agent-pilot-test.read-thing', $names, 'A read token should see the read-only tools.' );
		$this->assertNotContains( 'agent-pilot-test.write-thing', $names, 'A read token should never learn that the write tools exist.' );
	}

	public function test_a_read_token_is_refused_a_write_tool() {
		$this->register_ability( 'agent-pilot-test/write-thing' );

		$this->set_bearer( $this->issue_token( [ Authentication::SCOPE_READ ] )['value'] );

		$response = $this->post( $this->message( 'tools/call', [ 'name' => 'agent-pilot-test.write-thing' ] ) );

		$this->assertSame(
			403,
			$response->get_status(),
			'An ability that never declared itself readonly requires the write scope, which a read token does not carry.'
		);
	}

	public function test_a_write_token_may_call_a_write_tool() {
		$this->register_ability(
			'agent-pilot-test/write-thing',
			[
				'execute_callback' => fn() => 'written',
			]
		);

		// OAuth Pilot expands wp:write to imply wp:read at issuance.
		$this->set_bearer( $this->issue_token( [ Authentication::SCOPE_WRITE ] )['value'] );

		$response = $this->post( $this->message( 'tools/call', [ 'name' => 'agent-pilot-test.write-thing' ] ) );

		$this->assertSame( 'written', $response->get_data()['result']['content'][0]['text'], 'A write token should be able to call a write tool.' );
	}

	public function test_a_signed_in_user_is_accepted_without_a_token() {
		$this->register_ability( 'agent-pilot-test/write-thing' );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$response = $this->post( $this->message( 'tools/list' ) );

		$this->assertSame(
			200,
			$response->get_status(),
			'A site without OAuth Pilot, or an administrator using an Application Password, must still be able to reach the server.'
		);
		$this->assertContains(
			'agent-pilot-test.write-thing',
			wp_list_pluck( $response->get_data()['result']['tools'], 'name' ),
			'A request WordPress authenticated itself has no token to narrow, so every tool should be listed.'
		);
	}
}
