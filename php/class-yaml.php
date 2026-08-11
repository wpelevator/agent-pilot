<?php

namespace WPElevator\Agent_Pilot;

/**
 * Build a YAML document out of key-value pairs.
 *
 * Array values become nested block structures where entries with named keys
 * are emitted as mappings and entries with numeric keys are emitted as list
 * items. Scalar values are emitted as plain unquoted scalars whenever YAML
 * allows it, with a fallback to JSON encoding, which is valid YAML, only when
 * quoting is required.
 */
class Yaml {

	private const INDENT = '  ';

	/**
	 * Characters that carry special meaning at the start of a plain YAML scalar.
	 *
	 * @see https://yaml.org/spec/1.2.2/#53-indicator-characters
	 */
	private const INDICATOR_CHARACTERS = "-?:,[]{}#&*!|>'\"%@`";

	private array $data;

	public function __construct( array $data ) {
		$this->data = $data;
	}

	public function get_yaml(): string {
		return implode( "\n", $this->get_lines( $this->data ) );
	}

	private function get_lines( array $data, ?int $depth = 0 ): array {
		$indent = str_repeat( self::INDENT, $depth );
		$lines = [];

		foreach ( $data as $key => $value ) {
			if ( is_int( $key ) ) {
				$lines = array_merge( $lines, $this->get_list_item_lines( $value, $depth ) );
			} elseif ( is_array( $value ) ) {
				if ( empty( $value ) ) {
					$lines[] = sprintf( '%s%s: []', $indent, $this->encode_scalar( $key ) );
				} else {
					$lines[] = sprintf( '%s%s:', $indent, $this->encode_scalar( $key ) );
					$lines = array_merge( $lines, $this->get_lines( $value, $depth + 1 ) );
				}
			} else {
				$lines[] = sprintf( '%s%s: %s', $indent, $this->encode_scalar( $key ), $this->encode_scalar( $value ) );
			}
		}

		return $lines;
	}

	private function get_list_item_lines( $value, int $depth ): array {
		$indent = str_repeat( self::INDENT, $depth );

		if ( ! is_array( $value ) ) {
			return [ sprintf( '%s- %s', $indent, $this->encode_scalar( $value ) ) ];
		}

		if ( empty( $value ) ) {
			return [ sprintf( '%s- []', $indent ) ];
		}

		// Fold the nested block into the list item marker, as in `- name: value`.
		$lines = $this->get_lines( $value, $depth + 1 );
		$lines[0] = sprintf( '%s- %s', $indent, ltrim( $lines[0] ) );

		return $lines;
	}

	/**
	 * @param mixed $value Scalar key or value to encode.
	 */
	private function encode_scalar( $value ): string {
		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
			return (string) wp_json_encode( $value ); // Valid YAML booleans and numbers.
		}

		$value = (string) $value;

		if ( $this->needs_quoting( $value ) ) {
			return (string) wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		}

		return $value;
	}

	private function needs_quoting( string $value ): bool {
		if ( '' === $value ) {
			return true; // An empty plain scalar parses as null instead of an empty string.
		}

		if ( trim( $value ) !== $value ) {
			return true; // Leading and trailing whitespace survives only inside quotes.
		}

		if ( false !== strpos( self::INDICATOR_CHARACTERS, $value[0] ) ) {
			return true; // A leading indicator character would change the YAML node type.
		}

		if ( preg_match( '/[\r\n\t\'"]|: |:$| #/', $value ) ) {
			return true; // Quotes, control whitespace, mapping separators and comments need escaping.
		}

		if ( is_numeric( $value ) || preg_match( '/^(true|false|yes|no|on|off|null|~)$/i', $value ) ) {
			return true; // Quote values that YAML would cast away from a string.
		}

		return false;
	}
}
