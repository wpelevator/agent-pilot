<?php

namespace WPElevator\Agent_Pilot_Tests;

use WPElevator\Agent_Pilot\Response;

class Response_Test extends \WP_UnitTestCase {

	public function test_creates_a_markdown_response_with_shared_headers() {
		$body = "Example response\n";
		$response = Response::as_markdown( 200, $body, [ 'Cache-Control' => 'public, max-age=60' ] );
		$headers = $response->get_headers();

		$this->assertSame( 200, $response->get_status(), 'The response should preserve the requested status.' );
		$this->assertSame( $body, $response->get_body(), 'The response should preserve exact body bytes.' );
		$this->assertSame( 'text/markdown; charset=UTF-8', $headers['Content-Type'], 'Markdown responses should use the Markdown content type.' );
		$this->assertSame( 'public, max-age=60', $headers['Cache-Control'], 'Additional response headers should be preserved.' );
		$this->assertSame( (string) strlen( $body ), $headers['Content-Length'], 'The response should advertise the exact body length.' );
		$this->assertSame( '"' . hash( 'sha256', $body ) . '"', $response->get_header( 'ETag' ), 'The response should generate an ETag from the exact body bytes.' );
		$this->assertSame( '*', $headers['Access-Control-Allow-Origin'], 'Raw endpoint responses should retain public CORS access.' );
		$this->assertSame( 'nosniff', $headers['X-Content-Type-Options'], 'Raw endpoint responses should prevent MIME type sniffing.' );
		$this->assertSame( '', $response->get_header( 'Missing' ), 'Unknown response headers should resolve to an empty string.' );
	}

	public function test_creates_a_json_response_from_data() {
		$response = Response::as_json( 200, [ 'url' => 'https://example.org/agent-skill/example/' ] );

		$this->assertSame( 'application/json; charset=UTF-8', $response->get_header( 'Content-Type' ), 'JSON responses should use the JSON content type.' );
		$this->assertSame( '{"url":"https://example.org/agent-skill/example/"}', $response->get_body(), 'JSON responses should not escape slashes or unicode.' );
	}

	public function test_not_modified_response_preserves_the_original_response() {
		$response = Response::as_markdown( 200, "Example response\n" );
		$not_modified = $response->as_not_modified();

		$this->assertSame( 304, $not_modified->get_status(), 'The conditional response should use HTTP 304.' );
		$this->assertSame( '', $not_modified->get_body(), 'The conditional response should not contain a body.' );
		$this->assertSame( $response->get_header( 'ETag' ), $not_modified->get_header( 'ETag' ), 'The conditional response should retain the original entity tag.' );
		$this->assertSame( 200, $response->get_status(), 'Creating a conditional response should not mutate the original response.' );
	}

	public function test_creates_a_file_response_from_the_file_on_disk() {
		$body = "Asset bytes\n";
		$file_path = tempnam( get_temp_dir(), 'agent-pilot-' ) . '.txt';
		file_put_contents( $file_path, $body );

		try {
			$response = Response::as_file( 200, $file_path, 'example.txt' );

			$this->assertSame( $body, $response->get_body(), 'File responses should serve the exact bytes stored on disk.' );
			$this->assertSame( mime_content_type( $file_path ), $response->get_header( 'Content-Type' ), 'File responses should derive their content type from the file.' );
			$this->assertSame( 'attachment; filename="example.txt"', $response->get_header( 'Content-Disposition' ), 'File responses should be offered under the requested download filename.' );
			$this->assertSame( (string) strlen( $body ), $response->get_header( 'Content-Length' ), 'File responses should advertise the exact file length.' );
			$this->assertSame( '"' . hash( 'sha256', $body ) . '"', $response->get_header( 'ETag' ), 'File responses should generate an ETag from the exact body bytes.' );
			$this->assertSame(
				sprintf( 'attachment; filename="%s"', basename( $file_path ) ),
				Response::as_file( 200, $file_path )->get_header( 'Content-Disposition' ),
				'File responses should fall back to the name of the file on disk.'
			);
		} finally {
			unlink( $file_path );
		}
	}

	public function test_method_not_allowed_response_has_the_expected_contract() {
		$response = Response::method_not_allowed();

		$this->assertSame( 405, $response->get_status(), 'The method-not-allowed response should use HTTP 405.' );
		$this->assertSame( 'GET, HEAD', $response->get_header( 'Allow' ), 'The method-not-allowed response should advertise GET and HEAD.' );
		$this->assertSame( wp_get_nocache_headers()['Cache-Control'], $response->get_header( 'Cache-Control' ), 'The method-not-allowed response should use the WordPress no-cache headers.' );
		$this->assertSame( "Method Not Allowed\n", $response->get_body(), 'The method-not-allowed response should use a stable plain-text body.' );
	}

	public function test_not_found_response_has_the_expected_contract() {
		$response = Response::not_found();

		$this->assertSame( 404, $response->get_status(), 'Missing resource responses should use HTTP 404.' );
		$this->assertSame( 'text/plain; charset=UTF-8', $response->get_header( 'Content-Type' ), 'Missing resource responses should use the plain-text content type.' );
		$this->assertSame( wp_get_nocache_headers()['Cache-Control'], $response->get_header( 'Cache-Control' ), 'Missing resource responses should use the WordPress no-cache headers.' );
		$this->assertSame( "Not Found\n", $response->get_body(), 'Missing resource responses should use a stable body.' );
	}
}
