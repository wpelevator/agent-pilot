<?php

namespace WPElevator\Agent_Pilot;

use WP_Error;

/**
 * Publishes the Agent Plugins this site authors as WordPress Abilities, which
 * the MCP server then exposes as tools.
 *
 * The same reasoning as `Skill_Abilities` applies: what an agent can act on is
 * the generated package — the manifest, the MCP server definitions and the
 * skills it bundles — rather than the blocks an Agent Plugin is composed from,
 * and only reading is defined until writing block markup is designed. The names
 * these are registered under are bound by `Plugin`, as they are for skills.
 */
class Agent_Plugin_Abilities {

	private Agent_Plugins $plugins;

	private Agent_Plugin_Discovery $discovery;

	public function __construct( Agent_Plugins $plugins, Agent_Plugin_Discovery $discovery ) {
		$this->plugins = $plugins;
		$this->discovery = $discovery;
	}

	/**
	 * The ability that lists every Agent Plugin the caller is allowed to see.
	 */
	public function get_list_args(): array {
		return [
			'label' => __( 'List Agent Plugins', 'wpelevator-agent-pilot' ),
			'description' => __( 'List the Agent Plugins authored on this site, with the skills and MCP servers each one bundles, the URLs it is published under, and any validation errors that stop it from being served. These are portable agent packages rather than WordPress plugins. Published ones are listed for everyone; drafts and private ones only for a caller allowed to read them.', 'wpelevator-agent-pilot' ),
			'category' => Plugin::ABILITY_CATEGORY,
			'output_schema' => [
				'type' => 'object',
				'properties' => [
					'plugins' => [
						'type' => 'array',
						'items' => $this->get_plugin_schema(),
					],
				],
			],
			// Open to every caller for the reason `Skill_Abilities::get_list_args()` explains.
			'permission_callback' => '__return_true',
			'execute_callback' => [ $this, 'execute_list' ],
			'meta' => [
				'mcp' => [
					'public' => true,
					'type' => 'tool',
				],
				'annotations' => [
					'readonly' => true,
					'destructive' => false,
					'idempotent' => true,
				],
			],
		];
	}

	/**
	 * The ability that reads one Agent Plugin by name.
	 */
	public function get_read_args(): array {
		return [
			'label' => __( 'Get Agent Plugin', 'wpelevator-agent-pilot' ),
			'description' => __( 'Read one Agent Plugin by name. Returns the same metadata as the listing along with the contents of its plugin.json manifest. To read another of its files instead, such as mcp.json or a bundled skill, pass a path from its own files list as the file argument.', 'wpelevator-agent-pilot' ),
			'category' => Plugin::ABILITY_CATEGORY,
			'input_schema' => [
				'type' => 'object',
				'properties' => [
					'name' => [
						'type' => 'string',
						'description' => __( 'The name of the Agent Plugin, as reported by the listing.', 'wpelevator-agent-pilot' ),
					],
					'file' => [
						'type' => 'string',
						'description' => __( 'A path from the files list of the Agent Plugin, such as mcp.json or skills/example/SKILL.md. Defaults to plugin.json.', 'wpelevator-agent-pilot' ),
					],
				],
				'required' => [ 'name' ],
				'additionalProperties' => false,
			],
			'output_schema' => [
				'type' => 'object',
				'properties' => array_merge(
					$this->get_plugin_schema()['properties'],
					[
						'file' => [
							'type' => 'string',
							'description' => __( 'The path of the returned file.', 'wpelevator-agent-pilot' ),
						],
						'content' => [
							'type' => 'string',
							'description' => __( 'The contents of the returned file.', 'wpelevator-agent-pilot' ),
						],
					]
				),
			],
			// Open to every caller for the reason `Skill_Abilities::get_list_args()` explains.
			'permission_callback' => '__return_true',
			'execute_callback' => [ $this, 'execute_get' ],
			'meta' => [
				'mcp' => [
					'public' => true,
					'type' => 'tool',
				],
				'annotations' => [
					'readonly' => true,
					'destructive' => false,
					'idempotent' => true,
				],
			],
		];
	}

	/**
	 * @param mixed $input
	 */
	public function execute_list( $input = null ): array {
		return [
			'plugins' => array_map(
				fn ( Agent_Plugin_Post $plugin ): array => $this->get_plugin_data( $plugin ),
				$this->plugins->get_readable_plugins()
			),
		];
	}

	/**
	 * @return array|WP_Error
	 */
	public function execute_get( array $input ) {
		$name = (string) ( $input['name'] ?? '' );
		$plugin = $this->plugins->get_readable_plugin( $name );

		if ( ! $plugin ) {
			return new WP_Error(
				'agent_pilot_plugin_not_found',
				sprintf(
					/* translators: %s: the requested Agent Plugin name. */
					__( 'No Agent Plugin named %s is available to you.', 'wpelevator-agent-pilot' ),
					$name
				)
			);
		}

		$file = trim( (string) ( $input['file'] ?? '' ) );

		if ( '' === $file ) {
			$file = Agent_Plugin_Post::FILE_PLUGIN_JSON;
		}

		$content = $this->get_file_content( $plugin, $file );

		if ( is_wp_error( $content ) ) {
			return $content;
		}

		return array_merge(
			$this->get_plugin_data( $plugin ),
			[
				'file' => $file,
				'content' => $content,
			]
		);
	}

	/**
	 * The contents of one file of the package.
	 *
	 * A package carries the files of the skills it bundles, and carries which of
	 * them are assets, so a bundled asset is answered with the URL it can be
	 * downloaded from just as a skill's own asset is.
	 *
	 * @return string|WP_Error
	 */
	private function get_file_content( Agent_Plugin_Post $plugin, string $path ) {
		$file = $plugin->get_files()->get( $path );

		if ( ! $file ) {
			return new WP_Error(
				'agent_pilot_plugin_file_not_found',
				sprintf(
					/* translators: 1: the Agent Plugin name, 2: the requested file path. */
					__( 'The Agent Plugin %1$s has no file named %2$s.', 'wpelevator-agent-pilot' ),
					$plugin->get_name(),
					$path
				)
			);
		}

		if ( $file->is_asset() ) {
			return new WP_Error(
				'agent_pilot_plugin_file_is_asset',
				sprintf(
					/* translators: 1: the requested file path, 2: the download URL. */
					__( '%1$s is an asset published from the media library rather than generated text. Download it from %2$s.', 'wpelevator-agent-pilot' ),
					$path,
					(string) $file->get_url()
				)
			);
		}

		return $file->get_contents();
	}

	private function get_plugin_data( Agent_Plugin_Post $plugin ): array {
		return [
			'id' => $plugin->get_id(),
			'name' => $plugin->get_name(),
			'title' => $plugin->get_title(),
			'description' => $plugin->get_description(),
			'status' => $plugin->get_post()->post_status,
			'is_public' => $plugin->is_published(),
			'modified' => gmdate( 'c', $plugin->get_last_modified() ),
			'permalink' => $plugin->get_permalink(),
			'manifest_url' => $this->discovery->get_plugin_json_url( $plugin ),
			'mcp_url' => $this->discovery->get_mcp_json_url( $plugin ),
			'package_url' => $this->discovery->get_plugin_zip_url( $plugin ),
			'skills' => array_values(
				array_map(
					fn ( Skill_Post $skill ): string => $skill->get_name(),
					$plugin->get_skills()
				)
			),
			'mcp_servers' => array_map(
				fn ( MCP_Server $server ): string => $server->get_name(),
				$plugin->get_servers()
			),
			'errors' => $plugin->get_errors( $plugin->is_published() ),
			'files' => $plugin->get_files()->get_paths(),
		];
	}

	private function get_plugin_schema(): array {
		return [
			'type' => 'object',
			'properties' => [
				'id' => [
					'type' => 'integer',
					'description' => __( 'The WordPress post ID of the Agent Plugin.', 'wpelevator-agent-pilot' ),
				],
				'name' => [
					'type' => 'string',
					'description' => __( 'The Agent Plugin name, which is the post slug.', 'wpelevator-agent-pilot' ),
				],
				'title' => [ 'type' => 'string' ],
				'description' => [ 'type' => 'string' ],
				'status' => [
					'type' => 'string',
					'description' => __( 'The WordPress post status. Only a published Agent Plugin is publicly available.', 'wpelevator-agent-pilot' ),
				],
				'is_public' => [ 'type' => 'boolean' ],
				'modified' => [
					'type' => 'string',
					'description' => __( 'When the Agent Plugin or one of its skills last changed, in ISO 8601 UTC.', 'wpelevator-agent-pilot' ),
				],
				'permalink' => [ 'type' => 'string' ],
				'manifest_url' => [
					'type' => 'string',
					'description' => __( 'Where the generated plugin.json is served.', 'wpelevator-agent-pilot' ),
				],
				'mcp_url' => [
					'type' => [ 'string', 'null' ],
					'description' => __( 'Where the generated mcp.json is served.', 'wpelevator-agent-pilot' ),
				],
				'package_url' => [
					'type' => 'string',
					'description' => __( 'Where the package ZIP archive is served.', 'wpelevator-agent-pilot' ),
				],
				'skills' => [
					'type' => 'array',
					'items' => [ 'type' => 'string' ],
					'description' => __( 'The names of the Agent Skills this Agent Plugin bundles.', 'wpelevator-agent-pilot' ),
				],
				'mcp_servers' => [
					'type' => 'array',
					'items' => [ 'type' => 'string' ],
					'description' => __( 'The names of the MCP servers this Agent Plugin configures.', 'wpelevator-agent-pilot' ),
				],
				'errors' => [
					'type' => 'array',
					'items' => [ 'type' => 'string' ],
					'description' => __( 'Why this Agent Plugin cannot be served, when it is invalid.', 'wpelevator-agent-pilot' ),
				],
				'files' => [
					'type' => 'array',
					'items' => [ 'type' => 'string' ],
					'description' => __( 'The paths the package contains, which are the values the file argument accepts.', 'wpelevator-agent-pilot' ),
				],
			],
		];
	}
}
