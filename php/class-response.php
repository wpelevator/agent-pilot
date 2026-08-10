<?php

namespace WPElevator\Agent_Pilot;

class Response {

	private int $status;

	private array $headers;

	private string $body;

	private function __construct( int $status, array $headers, ?string $body = '' ) {
		$this->status = $status;
		$this->headers = $headers;
		$this->body = $body;
	}


	private static function get_default_headers( ?string $body = null ): array {
		$headers = [
			'Access-Control-Allow-Origin' => '*',
			'X-Content-Type-Options' => 'nosniff',
		];

		if ( isset( $body ) ) {
			$headers['Content-Length'] = (string) strlen( $body );
			$headers['ETag'] = sprintf( '"%s"', hash( 'sha256', $body ) );
		}

		return $headers;
	}

	public static function as_json( int $status, array $data, array $headers = [] ): self {
		$body = (string) wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		return new self(
			$status,
			array_merge(
				[
					'Content-Type' => 'application/json; charset=UTF-8',
				],
				$headers
			),
			$body
		);
	}

	public static function as_markdown( int $status, string $markdown, array $headers = [] ): self {
		return new self(
			$status,
			array_merge(
				[
					'Content-Type' => 'text/markdown; charset=UTF-8',
				],
				self::get_default_headers( $markdown ),
				$headers
			),
			$markdown
		);
	}

	public static function as_file( int $status, string $file_path, ?string $filename = null, array $headers = [] ): self {
		if ( ! $filename ) {
			$filename = basename( $file_path );
		}

		$body = file_get_contents( $file_path );

		return new self(
			$status,
			array_merge(
				[
					'Content-Type' => mime_content_type( $file_path ),
					'Content-Length' => (string) filesize( $file_path ),
					'Content-Disposition' => sprintf( 'attachment; filename="%s"', $filename ),
				],
				self::get_default_headers( $body ),
				$headers
			),
			$body
		);
	}

	public static function as_redirect( string $url, ?int $status = 302, ?array $headers = [] ): self {
		return new self(
			$status,
			array_merge(
				[
					'Location' => $url,
				],
				$headers
			)
		);
	}

	public static function method_not_allowed(): self {
		$response = "Method Not Allowed\n";

		return new self(
			405,
			array_merge(
				[
					'Content-Type' => 'text/plain; charset=UTF-8',
					'Allow' => 'GET, HEAD',
				],
				wp_get_nocache_headers(),
				self::get_default_headers( $response ),
			),
			$response
		);
	}

	public static function not_found(): self {
		$response = "Not Found\n";

		return new self(
			404,
			array_merge(
				[
					'Content-Type' => 'text/plain; charset=UTF-8',
				],
				wp_get_nocache_headers(),
				self::get_default_headers( $response )
			),
			$response
		);
	}

	public function get_status(): int {
		return $this->status;
	}

	public function get_headers(): array {
		return $this->headers;
	}

	public function get_header( string $name ): string {
		return isset( $this->headers[ $name ] ) ? (string) $this->headers[ $name ] : '';
	}

	public function get_body(): string {
		return $this->body;
	}

	public function as_not_modified(): self {
		$response = clone $this;
		$response->status = 304;
		$response->body = '';

		return $response;
	}
}
