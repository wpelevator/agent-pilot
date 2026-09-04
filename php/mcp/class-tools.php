<?php

namespace WPElevator\Agent_Pilot\MCP;

use WP_Ability;
use WP_Error;

/**
 * Presents WordPress Abilities as MCP tools.
 *
 * An ability already carries everything a tool definition needs — a namespaced
 * name, a label, a description, JSON Schema for input and output, behavioral
 * annotations, and its own permission callback — so this class is mostly a
 * faithful translation rather than a layer of its own policy. The two places it
 * does decide something are the scope an ability requires, and the shape a
 * non-object input schema is wrapped in.
 */
class Tools {

	/**
	 * The ability meta key that opts an ability in to MCP.
	 *
	 * This is the convention the official WordPress MCP Adapter established and
	 * that core's own `wp_get_abilities()` documentation uses as its nested meta
	 * example, so an ability written for either server works with both.
	 */
	public const META_KEY = 'mcp';

	/**
	 * MCP tool names may not contain a forward slash, and ability names may
	 * contain only lowercase alphanumerics, dashes and slashes. Since a dot can
	 * never appear in an ability name, swapping it for the slash is a lossless,
	 * reversible mapping: `core/read-settings` becomes `core.read-settings`.
	 */
	public const NAME_SEPARATOR = '.';

	/**
	 * The property a non-object ability input schema is wrapped in, because MCP
	 * requires a tool's `inputSchema` to be an object schema while an ability
	 * may legitimately declare a bare string or integer.
	 */
	public const WRAPPED_INPUT_PROPERTY = 'value';

	/**
	 * Whether this WordPress version has the Abilities API.
	 */
	public function is_available(): bool {
		return function_exists( 'wp_get_abilities' ) && function_exists( 'wp_get_ability' );
	}

	/**
	 * Every ability exposed to MCP, in a deterministic order.
	 *
	 * Ordering matters to clients: the spec asks servers to return tools in the
	 * same order across requests so that tool lists stay cacheable and model
	 * prompt caches keep hitting.
	 *
	 * @return WP_Ability[] Keyed by ability name.
	 */
	public function get_abilities(): array {
		if ( ! $this->is_available() ) {
			return [];
		}

		/**
		 * Filter the query that decides which abilities this MCP server exposes.
		 *
		 * Defaults to the abilities that opted in to MCP. Replacing these
		 * arguments replaces that rule too, which is how a site exposes
		 * abilities it does not control: passing `[ 'namespace' => 'core' ]`
		 * exposes every core ability without editing any registration.
		 *
		 * @param array $args Arguments for `wp_get_abilities()`.
		 */
		$args = (array) apply_filters(
			'agent_pilot__mcp_abilities',
			[
				'item_include_callback' => [ $this, 'is_ability_public' ],
			]
		);

		$abilities = wp_get_abilities( $args );

		ksort( $abilities );

		return $abilities;
	}

	/**
	 * Whether an ability opted in to being exposed over MCP.
	 */
	public function is_ability_public( WP_Ability $ability ): bool {
		return $this->is_meta_public( $ability->get_meta() );
	}

	/**
	 * Resolve MCP exposure from ability metadata.
	 *
	 * An explicit `meta.mcp.public` wins; when it is absent or null, exposure is
	 * inherited from the high-level `meta.public` flag. That is the same
	 * most-specific-first resolution core itself uses to derive `show_in_rest`
	 * from `public`, and the same one the official MCP Adapter applies, so an
	 * ability written for either server is exposed identically by both.
	 *
	 * A malformed `meta.mcp` fails closed rather than being ignored.
	 *
	 * @param array<string, mixed> $meta The ability metadata.
	 */
	public function is_meta_public( array $meta ): bool {
		$mcp = $meta[ self::META_KEY ] ?? [];

		if ( ! is_array( $mcp ) ) {
			return false;
		}

		if ( isset( $mcp['public'] ) ) {
			return (bool) $mcp['public'];
		}

		return true === ( $meta['public'] ?? false );
	}

	/**
	 * The tool definitions a given caller may see.
	 *
	 * The advertised set narrows with the caller's scopes, which the spec
	 * explicitly permits: credentials are per-request input rather than
	 * connection state, so a read-only token simply never learns that the write
	 * tools exist.
	 */
	public function get_tools( Identity $identity ): array {
		$tools = [];

		foreach ( $this->get_abilities() as $ability ) {
			if ( ! $identity->has_scopes( $this->get_required_scopes( $ability ) ) ) {
				continue;
			}

			$tools[] = $this->to_tool( $ability );
		}

		return $tools;
	}

	/**
	 * Translate one ability into an MCP tool definition.
	 */
	public function to_tool( WP_Ability $ability ): array {
		$tool = [
			'name' => $this->get_tool_name( $ability->get_name() ),
			'description' => $ability->get_description(),
			'inputSchema' => $this->get_input_schema( $ability ),
		];

		$label = $ability->get_label();

		if ( '' !== $label ) {
			$tool['title'] = $label;
		}

		$output_schema = $this->get_output_schema( $ability );

		if ( ! empty( $output_schema ) ) {
			$tool['outputSchema'] = $output_schema;
		}

		$annotations = $this->get_annotations( $ability );

		if ( ! empty( $annotations ) ) {
			$tool['annotations'] = $annotations;
		}

		/**
		 * Filter one generated MCP tool definition.
		 *
		 * @param array      $tool    The tool definition.
		 * @param WP_Ability $ability The ability it was generated from.
		 */
		return (array) apply_filters( 'agent_pilot__mcp_tool', $tool, $ability );
	}

	public function get_tool_name( string $ability_name ): string {
		return str_replace( '/', self::NAME_SEPARATOR, $ability_name );
	}

	public function get_ability_name( string $tool_name ): string {
		return str_replace( self::NAME_SEPARATOR, '/', $tool_name );
	}

	/**
	 * The OAuth scopes a tool requires, derived from the ability's own
	 * annotation of itself.
	 *
	 * An ability that declares `readonly` needs only read access; anything else,
	 * including the `null` default, is treated as a write. Defaulting an
	 * unannotated ability to write is the safe direction: an ability that fails
	 * to describe itself is not assumed harmless.
	 *
	 * @return string[]
	 */
	public function get_required_scopes( WP_Ability $ability ): array {
		$annotations = (array) $ability->get_meta_item( 'annotations', [] );

		$scopes = ( true === ( $annotations['readonly'] ?? null ) )
			? [ Authentication::SCOPE_READ ]
			: [ Authentication::SCOPE_WRITE ];

		/**
		 * Override the scopes one ability requires when called over MCP.
		 *
		 * @param string[]   $scopes  The derived scopes.
		 * @param WP_Ability $ability The ability.
		 */
		return (array) apply_filters( 'agent_pilot__mcp_tool_scopes', $scopes, $ability );
	}

	/**
	 * MCP requires `inputSchema` to be an object schema. An ability may declare
	 * any JSON Schema, so a non-object one is wrapped in a single property and
	 * unwrapped again before execution.
	 */
	public function get_input_schema( WP_Ability $ability ): array {
		$schema = $ability->get_input_schema();

		if ( empty( $schema ) ) {
			// The recommended spelling for a tool that takes no arguments.
			return [
				'type' => 'object',
				'additionalProperties' => false,
			];
		}

		if ( 'object' === ( $schema['type'] ?? null ) ) {
			/*
			 * On an object schema JSON Schema expects `required` to list
			 * property names. Abilities also allow a boolean `required` to mark
			 * the input as a whole, which would make the schema invalid here.
			 */
			if ( isset( $schema['required'] ) && ! is_array( $schema['required'] ) ) {
				unset( $schema['required'] );
			}

			return $schema;
		}

		$is_required = ! empty( $schema['required'] );

		unset( $schema['required'] );

		$wrapped = [
			'type' => 'object',
			'properties' => [
				self::WRAPPED_INPUT_PROPERTY => $schema,
			],
			'additionalProperties' => false,
		];

		if ( $is_required ) {
			$wrapped['required'] = [ self::WRAPPED_INPUT_PROPERTY ];
		}

		return $wrapped;
	}

	/**
	 * Whether this ability's input had to be wrapped to satisfy MCP.
	 */
	public function is_input_wrapped( WP_Ability $ability ): bool {
		$schema = $ability->get_input_schema();

		return ! empty( $schema ) && 'object' !== ( $schema['type'] ?? null );
	}

	/**
	 * Only an object output schema is advertised.
	 *
	 * The current revision allows any JSON Schema, but the legacy revisions this
	 * server also speaks expect an object, and a client that validates
	 * `structuredContent` against a mismatched schema fails the whole call. An
	 * unadvertised schema costs only the structured copy of the result, which is
	 * why `structuredContent` below is emitted on exactly the same condition.
	 */
	public function get_output_schema( WP_Ability $ability ): array {
		$schema = $ability->get_output_schema();

		if ( empty( $schema ) || 'object' !== ( $schema['type'] ?? null ) ) {
			return [];
		}

		if ( isset( $schema['required'] ) && ! is_array( $schema['required'] ) ) {
			unset( $schema['required'] );
		}

		return $schema;
	}

	/**
	 * An ability's behavioral annotations map one to one onto MCP's tool hints.
	 * Only the ones the ability actually declared are passed on, so that an
	 * undeclared hint stays undeclared rather than becoming a false promise.
	 */
	public function get_annotations( WP_Ability $ability ): array {
		$annotations = (array) $ability->get_meta_item( 'annotations', [] );

		$hints = [];

		foreach ( [
			'readonly' => 'readOnlyHint',
			'destructive' => 'destructiveHint',
			'idempotent' => 'idempotentHint',
		] as $key => $hint ) {
			if ( isset( $annotations[ $key ] ) && is_bool( $annotations[ $key ] ) ) {
				$hints[ $hint ] = $annotations[ $key ];
			}
		}

		$label = $ability->get_label();

		if ( '' !== $label ) {
			$hints['title'] = $label;
		}

		return $hints;
	}

	/**
	 * Look up the ability behind a tool name, but only among the ones this
	 * server exposes. Resolving through `wp_get_ability()` directly would let a
	 * caller reach an ability that never opted in to MCP.
	 */
	public function get_ability( string $tool_name ): ?WP_Ability {
		$abilities = $this->get_abilities();

		return $abilities[ $this->get_ability_name( $tool_name ) ] ?? null;
	}

	/**
	 * Execute a tool and build its `CallToolResult`.
	 *
	 * @param array $arguments The `arguments` member of the `tools/call` params.
	 *
	 * @return array|WP_Error The result, or a WP_Error for the failures that are
	 *                        protocol errors rather than tool errors.
	 */
	public function call( string $tool_name, array $arguments, Identity $identity ) {
		$ability = $this->get_ability( $tool_name );

		if ( ! $ability ) {
			return new WP_Error(
				'agent_pilot_mcp_unknown_tool',
				sprintf(
					/* translators: %s: the requested MCP tool name. */
					__( 'Unknown tool: %s', 'wpelevator-agent-pilot' ),
					$tool_name
				),
				[ 'status' => 404 ]
			);
		}

		$required_scopes = $this->get_required_scopes( $ability );

		if ( ! $identity->has_scopes( $required_scopes ) ) {
			return new WP_Error(
				'agent_pilot_mcp_insufficient_scope',
				__( 'The access token does not carry the required scope.', 'wpelevator-agent-pilot' ),
				[
					'status' => 403,
					'scope' => $required_scopes,
				]
			);
		}

		$input = $this->get_input( $ability, $arguments );

		/*
		 * `execute()` deliberately returns a generic message when permissions
		 * fail, so that a denied caller learns nothing about why. Checking first
		 * lets the failure be reported as an authorization problem rather than
		 * as an ordinary tool error the model would try to correct by retrying.
		 */
		$permitted = $ability->check_permissions( $input );

		if ( true !== $permitted ) {
			return new WP_Error(
				'agent_pilot_mcp_forbidden',
				sprintf(
					/* translators: %s: the requested MCP tool name. */
					__( 'You are not allowed to use the tool: %s', 'wpelevator-agent-pilot' ),
					$tool_name
				),
				[ 'status' => 403 ]
			);
		}

		$result = $ability->execute( $input );

		if ( is_wp_error( $result ) ) {
			return $this->to_error_result( $result );
		}

		return $this->to_result( $ability, $result );
	}

	/**
	 * Unwrap the tool arguments back into the shape the ability declared.
	 *
	 * @param array $arguments
	 *
	 * @return mixed
	 */
	public function get_input( WP_Ability $ability, array $arguments ) {
		if ( empty( $ability->get_input_schema() ) ) {
			/*
			 * An ability with no input schema rejects any non-null input, so an
			 * empty arguments object has to become null rather than an array.
			 */
			return null;
		}

		if ( $this->is_input_wrapped( $ability ) ) {
			return $arguments[ self::WRAPPED_INPUT_PROPERTY ] ?? null;
		}

		return $arguments;
	}

	/**
	 * A successful result.
	 *
	 * The JSON text block is not redundant with `structuredContent`: the spec
	 * asks servers that return structured content to also serialize it into a
	 * text block, so that clients which do not read structured results still see
	 * the value.
	 *
	 * @param mixed $result
	 */
	public function to_result( WP_Ability $ability, $result ): array {
		$text = is_string( $result )
			? $result
			: (string) wp_json_encode( $result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		$payload = [
			'content' => [
				[
					'type' => 'text',
					'text' => $text,
				],
			],
			'isError' => false,
		];

		if ( ! empty( $this->get_output_schema( $ability ) ) && is_array( $result ) ) {
			$payload['structuredContent'] = $result;
		}

		return $payload;
	}

	/**
	 * A tool execution failure.
	 *
	 * Reported inside the result with `isError` rather than as a JSON-RPC error,
	 * because these are the failures a model can act on — bad arguments, a value
	 * out of range, a business rule — and clients pass them back to the model to
	 * let it correct itself.
	 */
	public function to_error_result( WP_Error $error ): array {
		return [
			'content' => [
				[
					'type' => 'text',
					'text' => $error->get_error_message(),
				],
			],
			'isError' => true,
		];
	}
}
