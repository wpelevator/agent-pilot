<?php

namespace WPElevator\Agent_Pilot_Tests;

use WPElevator\Agent_Pilot\Request;
use WPElevator\Agent_Pilot\Response;
use WPElevator\Agent_Pilot\Response_Emitter;

class Response_Emitter_Test extends \WP_UnitTestCase {

	public function test_matching_etag_prepares_a_not_modified_response() {
		$response = Response::as_markdown( 200, "Example\n" );
		$response_emitter = new Response_Emitter( new Request( 'GET', [ 'If-None-Match' => $response->get_header( 'ETag' ) ] ) );

		$prepared = $response_emitter->prepare_response( $response );

		$this->assertSame( 304, $prepared->get_status(), 'A matching ETag should turn a successful GET into a 304 response.' );
		$this->assertSame( '', $prepared->get_body(), 'A 304 response should not carry response data.' );
		$this->assertSame( 200, $response->get_status(), 'Preparing a response should not mutate the endpoint-owned response object.' );
	}

	public function test_non_matching_etag_preserves_the_response() {
		$response = Response::as_markdown( 200, "Example\n" );
		$response_emitter = new Response_Emitter( new Request( 'GET', [ 'If-None-Match' => '"different"' ] ) );

		$prepared = $response_emitter->prepare_response( $response );

		$this->assertSame( 200, $prepared->get_status(), 'A non-matching ETag should preserve the successful response status.' );
		$this->assertSame( "Example\n", $prepared->get_body(), 'A non-matching ETag should preserve the response body.' );
	}

	public function test_unsupported_methods_use_the_shared_method_not_allowed_response() {
		$response = Response::as_markdown( 200, "Example\n" );
		$response_emitter = new Response_Emitter( new Request( 'POST' ) );

		$prepared = $response_emitter->prepare_response( $response );
		$headers = $prepared->get_headers();

		$this->assertSame( 405, $prepared->get_status(), 'Methods other than GET and HEAD should return HTTP 405.' );
		$this->assertSame( 'GET, HEAD', $headers['Allow'], 'HTTP 405 responses should advertise the supported methods.' );
		$this->assertSame( wp_get_nocache_headers()['Cache-Control'], $headers['Cache-Control'], 'HTTP 405 responses should not be cached.' );
		$this->assertSame( "Method Not Allowed\n", $prepared->get_body(), 'HTTP 405 responses should use the shared plain-text body.' );
	}

	public function test_body_output_respects_the_request_method_and_response_status() {
		$response = Response::as_markdown( 200, "Example\n" );
		$get_emitter = new Response_Emitter( new Request( 'GET', [ 'If-None-Match' => $response->get_header( 'ETag' ) ] ) );
		$head_emitter = new Response_Emitter( new Request( 'HEAD' ) );
		$not_modified = $get_emitter->prepare_response( $response );

		$this->assertSame( "Example\n", ( new Response_Emitter( new Request( 'GET' ) ) )->get_body( $response ), 'GET responses should emit their exact body bytes.' );
		$this->assertSame( '', $head_emitter->get_body( $response ), 'HEAD responses should suppress the body without changing endpoint data.' );
		$this->assertSame( '', $get_emitter->get_body( $not_modified ), 'HTTP 304 responses should never emit a body.' );
	}
}
