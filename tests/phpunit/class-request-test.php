<?php

namespace WPElevator\Agent_Pilot_Tests;

use WPElevator\Agent_Pilot\Request;

class Request_Test extends \WP_UnitTestCase {

	public function test_normalizes_the_request_method() {
		$this->assertSame( 'GET', ( new Request() )->get_method(), 'Requests should default to GET.' );
		$this->assertSame( 'HEAD', ( new Request( ' head ' ) )->get_method(), 'Request methods should be trimmed and normalized to uppercase.' );
		$this->assertTrue( ( new Request( 'GET' ) )->is_method( 'GET', 'HEAD' ), 'Method checks should match any explicitly supported method.' );
		$this->assertFalse( ( new Request( 'POST' ) )->is_method( 'GET', 'HEAD' ), 'Method checks should reject methods outside the supported list.' );
	}

	public function test_etag_matching_uses_http_weak_comparison() {
		$etag = '"abc123"';

		$this->assertTrue( ( new Request( 'GET', [ 'If-None-Match' => $etag ] ) )->matches_etag( $etag ), 'An exact ETag should match.' );
		$this->assertTrue( ( new Request( 'GET', [ 'If-None-Match' => 'W/' . $etag ] ) )->matches_etag( $etag ), 'A weak ETag should match for GET and HEAD.' );
		$this->assertTrue( ( new Request( 'GET', [ 'If-None-Match' => '"other", ' . $etag ] ) )->matches_etag( $etag ), 'Any matching ETag in a list should match.' );
		$this->assertTrue( ( new Request( 'GET', [ 'If-None-Match' => '*' ] ) )->matches_etag( $etag ), 'The If-None-Match wildcard should match an existing response.' );
		$this->assertFalse( ( new Request( 'GET', [ 'If-None-Match' => '"other"' ] ) )->matches_etag( $etag ), 'An unrelated ETag should not match.' );
		$this->assertFalse( ( new Request() )->matches_etag( $etag ), 'A missing If-None-Match value should not match.' );
	}

	public function test_normalizes_request_header_names_and_values() {
		$request = new Request(
			'GET',
			[
				'If_None_Match' => ' "abc123" ',
				'X-Custom-Header' => ' value ',
				'Ignored' => [ 'not', 'scalar' ],
			]
		);

		$this->assertSame( '"abc123"', $request->get_header( 'IF-NONE-MATCH' ), 'Header lookup should be case-insensitive and normalize underscores to hyphens.' );
		$this->assertSame( 'value', $request->get_header( 'x_custom_header' ), 'Header values should be trimmed and available through normalized names.' );
		$this->assertSame( '', $request->get_header( 'ignored' ), 'Non-scalar header values should not enter the request snapshot.' );
		$this->assertSame(
			[
				'if-none-match' => '"abc123"',
				'x-custom-header' => 'value',
			],
			$request->get_headers(),
			'Stored request header keys should use lowercase hyphenated names.'
		);
	}

	public function test_creates_a_request_snapshot_from_server_globals() {
		$server = $_SERVER;

		try {
			unset( $_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_IF_NONE_MATCH'] );
			$this->assertSame( 'GET', Request::from_globals()->get_method(), 'Server requests without an explicit method should default to GET.' );

			$_SERVER['REQUEST_METHOD'] = 'head';
			$_SERVER['HTTP_IF_NONE_MATCH'] = 'W/"abc123"';
			$_SERVER['HTTP_X_AGENT_TEST'] = 'available';
			$_SERVER['CONTENT_TYPE'] = 'text/markdown';
			$request = Request::from_globals();

			$this->assertSame( 'HEAD', $request->get_method(), 'The global request method should be normalized.' );
			$this->assertTrue( $request->matches_etag( '"abc123"' ), 'The global If-None-Match header should be captured by the request snapshot.' );
			$this->assertSame( 'available', $request->get_header( 'x-agent-test' ), 'CGI HTTP variables should become normalized request headers.' );
			$this->assertSame( 'text/markdown', $request->get_header( 'content-type' ), 'CGI content headers should be included even though they lack the HTTP prefix.' );
		} finally {
			$_SERVER = $server;
		}
	}
}
