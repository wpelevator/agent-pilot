<?php

namespace WPElevator\Agent_Pilot\MCP;

/**
 * JSON-RPC 2.0 framing for the MCP endpoint.
 *
 * MCP removed JSON-RPC batching in the 2025-06-18 revision, so a request body
 * is always exactly one message. That keeps this class to parsing one message
 * and building the three reply shapes: a result, an error, and nothing at all
 * for a notification.
 */
class Json_Rpc {

	public const VERSION = '2.0';

	public const PARSE_ERROR = -32700;

	public const INVALID_REQUEST = -32600;

	public const METHOD_NOT_FOUND = -32601;

	public const INVALID_PARAMS = -32602;

	public const INTERNAL_ERROR = -32603;

	/**
	 * The MCP specific code for a protocol version the server does not implement.
	 */
	public const UNSUPPORTED_PROTOCOL_VERSION = -32022;

	/**
	 * Decode a request body into a single message.
	 *
	 * @return array|null The message, or null when the body is not one valid
	 *                    JSON-RPC message.
	 */
	public static function parse( string $body ): ?array {
		$decoded = json_decode( $body, true );

		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
			return null;
		}

		// A batch is a list rather than an object, and is no longer part of MCP.
		if ( ! self::is_object( $decoded ) ) {
			return null;
		}

		return $decoded;
	}

	/**
	 * Whether a decoded value is a JSON object rather than a JSON array.
	 */
	public static function is_object( array $value ): bool {
		return empty( $value ) || array_keys( $value ) !== range( 0, count( $value ) - 1 );
	}

	/**
	 * Whether a message is well formed enough to act on.
	 */
	public static function is_valid( array $message ): bool {
		if ( ( $message['jsonrpc'] ?? '' ) !== self::VERSION ) {
			return false;
		}

		if ( empty( $message['method'] ) || ! is_string( $message['method'] ) ) {
			return false;
		}

		// An id is optional, but when present it must be a string or a number.
		if ( array_key_exists( 'id', $message ) && ! is_string( $message['id'] ) && ! is_int( $message['id'] ) && ! is_float( $message['id'] ) ) {
			return false;
		}

		return true;
	}

	/**
	 * A message with no id is a notification, which is acknowledged rather than
	 * answered.
	 */
	public static function is_notification( array $message ): bool {
		return ! array_key_exists( 'id', $message );
	}

	/**
	 * @return string|int|float|null
	 */
	public static function get_id( array $message ) {
		return $message['id'] ?? null;
	}

	public static function get_method( array $message ): string {
		return (string) ( $message['method'] ?? '' );
	}

	public static function get_params( array $message ): array {
		$params = $message['params'] ?? [];

		return is_array( $params ) ? $params : [];
	}

	/**
	 * @param string|int|float|null $id
	 */
	public static function result( $id, array $result ): array {
		return [
			'jsonrpc' => self::VERSION,
			'id' => $id,
			'result' => $result,
		];
	}

	/**
	 * @param string|int|float|null $id
	 */
	public static function error( $id, int $code, string $message, ?array $data = null ): array {
		$error = [
			'code' => $code,
			'message' => $message,
		];

		if ( isset( $data ) ) {
			$error['data'] = $data;
		}

		return [
			'jsonrpc' => self::VERSION,
			'id' => $id,
			'error' => $error,
		];
	}
}
