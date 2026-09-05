<?php

namespace WPElevator\Agent_Pilot\MCP;

/**
 * MCP server settings.
 *
 * Each setting is its own option rather than one serialized array, so every one
 * of them registers with its own type, default and sanitizer. That is what lets
 * WordPress expose them individually on `/wp/v2/settings`, validate them against
 * a real schema, and give other code a single named option to filter.
 */
class Settings {

	public const OPTION_PREFIX = 'agent_pilot__';

	public const GROUP = 'agent_pilot';

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public function get_schema(): array {
		return [
			'mcp_enabled' => [
				'type' => 'boolean',
				// The MCP server executes site functionality, so activating the
				// plugin must never expose it on its own.
				'default' => false,
				'description' => __( 'Whether the MCP server endpoint accepts requests.', 'wpelevator-agent-pilot' ),
				'sanitize_callback' => [ $this, 'sanitize_bool' ],
			],
			'mcp_disabled_abilities' => [
				'type' => 'array',
				'default' => [],
				'description' => __( 'Abilities excluded from Agent Pilot MCP tools.', 'wpelevator-agent-pilot' ),
				'sanitize_callback' => [ $this, 'sanitize_ability_names' ],
				'schema' => [
					'type' => 'array',
					'items' => [ 'type' => 'string' ],
				],
			],
		];
	}

	public function register(): void {
		foreach ( $this->get_schema() as $key => $definition ) {
			register_setting(
				self::GROUP,
				$this->get_option_name( $key ),
				[
					'type' => $definition['type'],
					'label' => $definition['description'],
					'description' => $definition['description'],
					'default' => $definition['default'],
					'sanitize_callback' => $definition['sanitize_callback'],
					'show_in_rest' => [
						'name' => $this->get_option_name( $key ),
						'schema' => $definition['schema'] ?? [
							'type' => $definition['type'],
							'description' => $definition['description'],
						],
					],
				]
			);
		}
	}

	public function get_option_name( string $key ): string {
		return self::OPTION_PREFIX . $key;
	}

	/**
	 * @return mixed
	 */
	public function get( string $key ) {
		$schema = $this->get_schema();

		if ( ! isset( $schema[ $key ] ) ) {
			return null;
		}

		return get_option( $this->get_option_name( $key ), $schema[ $key ]['default'] );
	}

	public function is_mcp_enabled(): bool {
		/**
		 * Override whether the MCP server accepts requests.
		 *
		 * @param bool $enabled The stored setting.
		 */
		return (bool) apply_filters( 'agent_pilot__mcp_enabled', (bool) $this->get( 'mcp_enabled' ) );
	}

	public function sanitize_bool( $value ): bool {
		return (bool) $value;
	}

	public function get_disabled_abilities(): array {
		return $this->sanitize_ability_names( $this->get( 'mcp_disabled_abilities' ) );
	}

	public function sanitize_ability_names( $value ): array {
		$names = [];

		foreach ( (array) $value as $name ) {
			if ( is_string( $name ) && preg_match( '/^[a-z0-9-]+\/[a-z0-9-]+$/', $name ) ) {
				$names[] = $name;
			}
		}

		$names = array_values( array_unique( $names ) );
		sort( $names );

		return $names;
	}
}
