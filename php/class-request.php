<?php

namespace WPElevator\Agent_Pilot;

class Request {

	private string $method;

	private array $headers;

	public function __construct( string $method = 'GET', array $headers = [] ) {
		$this->method = strtoupper( trim( $method ) );
		$this->headers = [];

		foreach ( $headers as $name => $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}

			$name = self::normalize_header_name( (string) $name );

			if ( '' !== $name ) {
				$this->headers[ $name ] = trim( (string) $value );
			}
		}
	}

	public static function from_globals(): self {
		$method = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) );
		$headers = [];
		$special_headers = [
			'CONTENT_TYPE' => 'content-type',
			'CONTENT_LENGTH' => 'content-length',
			'CONTENT_MD5' => 'content-md5',
		];

		foreach ( $_SERVER as $name => $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}

			if ( 0 === strpos( $name, 'HTTP_' ) ) {
				$header_name = substr( $name, 5 );
			} elseif ( isset( $special_headers[ $name ] ) ) {
				$header_name = $special_headers[ $name ];
			} else {
				continue;
			}

			$headers[ self::normalize_header_name( $header_name ) ] = sanitize_text_field( wp_unslash( (string) $value ) );
		}

		return new self( $method, $headers );
	}

	public function get_method(): string {
		return $this->method;
	}

	public function is_method( string ...$methods ): bool {
		return in_array( $this->method, $methods, true );
	}

	public function get_headers(): array {
		return $this->headers;
	}

	public function get_header( string $name ): string {
		$name = self::normalize_header_name( $name );

		return $this->headers[ $name ] ?? '';
	}

	public function matches_etag( string $etag ): bool {
		foreach ( explode( ',', $this->get_header( 'if-none-match' ) ) as $candidate ) {
			$candidate = trim( $candidate );

			if ( '*' === $candidate ) {
				return true;
			}

			if ( 0 === stripos( $candidate, 'W/' ) ) {
				$candidate = trim( substr( $candidate, 2 ) );
			}

			if ( hash_equals( $etag, $candidate ) ) {
				return true;
			}
		}

		return false;
	}

	private static function normalize_header_name( string $name ): string {
		return strtolower( str_replace( '_', '-', trim( $name ) ) );
	}
}
