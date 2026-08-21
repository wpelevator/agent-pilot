<?php

namespace WPElevator\Agent_Pilot;

use WP_Block;

/** A raw MCP server definition supplied by the package author. */
class MCP_Server {

	private string $name;
	private string $definition;
	private ?array $data = null;

	public function __construct( WP_Block $block ) {
		$this->name = trim( (string) ( $block->attributes['name'] ?? '' ) );
		$this->definition = (string) ( $block->attributes['definition'] ?? '{}' );
	}

	public function get_name(): string {
		return $this->name;
	}

	public function get_errors(): array {
		if ( '' === $this->name ) {
			return [ 'MCP server name is required.' ];
		}

		if ( null === $this->decode() ) {
			return [ 'MCP server definition must be a JSON object.' ];
		}

		return [];
	}

	public function to_array(): array {
		return $this->decode() ?? [];
	}

	private function decode(): ?array {
		if ( null !== $this->data ) {
			return $this->data;
		}

		$decoded = json_decode( $this->definition, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) || ( ! empty( $decoded ) && array_keys( $decoded ) === range( 0, count( $decoded ) - 1 ) ) ) {
			return null;
		}

		$this->data = $decoded;
		return $this->data;
	}
}
