<?php

namespace WPElevator\Agent_Pilot;

use WP_Block;

/**
 * An Agent Plugin authored as blocks in a post.
 *
 * A package composes other packages: the skills it bundles are published under
 * their own directories inside this one. Which is why everything below asks the
 * bundled skills only for what `Agent_Package` promises — a name, files, when
 * they changed, whether they are published — and never for the post each one
 * happens to be today.
 */
class Agent_Plugin_Post extends Post implements Agent_Package {

	public const BLOCK_NAME_PLUGIN = 'agent-pilot/agent-plugin';
	public const BLOCK_NAME_SKILL = 'agent-pilot/agent-plugin-skill';
	public const BLOCK_NAME_MCP = 'agent-pilot/agent-plugin-mcp-server';

	public const FILE_PLUGIN_JSON = 'plugin.json';
	public const FILE_MCP_JSON = 'mcp.json';

	public const SCHEMA = 'https://agent-plugins.org/schemas/1.0.0/plugin.schema.json';
	public const MCP_SCHEMA = 'https://agent-plugins.org/schemas/1.0.0/mcp.schema.json';

	/**
	 * The Agent Plugins name pattern, which is stricter than a WordPress slug.
	 */
	private const NAME_PATTERN = '/^[a-z0-9](?:[a-z0-9.-]{0,62}[a-z0-9])?$/';

	protected function get_draft_name(): string {
		return sprintf( '%s-%d-draft', Plugin::PERMALINK_PREFIX_AGENT_PLUGIN, $this->post->ID );
	}

	private function get_root_block(): ?WP_Block {
		$blocks = $this->get_blocks( [ self::BLOCK_NAME_PLUGIN ] );

		return ! empty( $blocks ) ? reset( $blocks ) : null;
	}

	public function get_manifest(): array {
		$root = $this->get_root_block();
		$attributes = $root ? $root->attributes : [];

		$manifest = [
			'$schema' => self::SCHEMA,
			'name' => $this->get_name(),
		];

		$description = $this->get_description();

		if ( '' !== $description ) {
			$manifest['description'] = $description;
		}

		foreach ( [ 'license' ] as $key ) {
			if ( ! empty( $attributes[ $key ] ) && is_string( $attributes[ $key ] ) ) {
				$manifest[ $key ] = trim( $attributes[ $key ] );
			}
		}

		foreach ( [ 'author', 'extensions' ] as $key ) {
			if ( ! empty( $attributes[ $key ] ) && is_array( $attributes[ $key ] ) ) {
				$manifest[ $key ] = $attributes[ $key ];
			}
		}

		return $manifest;
	}

	/**
	 * The packages this one bundles, keyed by the post each was selected from.
	 *
	 * Selecting a skill is selecting a post today, which is why the lookup below
	 * resolves one. Everything downstream treats what comes back as a package.
	 *
	 * @return Agent_Package[]
	 */
	public function get_skills(): array {
		$skills = [];

		foreach ( $this->get_selected_skill_ids() as $skill_id ) {
			$skill = Skill_Post::from_post_id( $skill_id );

			if ( $skill ) {
				$skills[ $skill_id ] = $skill;
			}
		}

		return $skills;
	}

	/**
	 * The post IDs the skill blocks select.
	 *
	 * A block with nothing selected yet contributes nothing rather than failing
	 * the package: inserting the block is the first half of choosing a skill.
	 *
	 * @return int[]
	 */
	private function get_selected_skill_ids(): array {
		$ids = array_map(
			fn ( WP_Block $block ): int => (int) ( $block->attributes['skillId'] ?? 0 ),
			$this->get_blocks( [ self::BLOCK_NAME_SKILL ] )
		);

		return array_values( array_filter( $ids ) );
	}

	/**
	 * @return MCP_Server[]
	 */
	public function get_servers(): array {
		$servers = array_map(
			fn ( WP_Block $block ): MCP_Server => new MCP_Server( $block ),
			$this->get_blocks( [ self::BLOCK_NAME_MCP ] )
		);

		usort( $servers, fn ( MCP_Server $a, MCP_Server $b ): int => strcmp( $a->get_name(), $b->get_name() ) );

		return $servers;
	}

	/**
	 * Why this package cannot be served, if it cannot.
	 *
	 * @param bool $is_public Whether the package is being served publicly, which
	 *                        its bundled skills have to be as well.
	 *
	 * @return string[]
	 */
	public function get_errors( bool $is_public = false ): array {
		$errors = array_merge(
			$this->get_name_errors(),
			$this->get_skill_errors( $is_public ),
			$this->get_server_errors(),
			$this->get_composition_errors()
		);

		return array_values( array_unique( $errors ) );
	}

	/**
	 * @return string[]
	 */
	private function get_name_errors(): array {
		$name = $this->get_name();

		$is_valid = preg_match( self::NAME_PATTERN, $name )
			&& false === strpos( $name, '--' )
			&& false === strpos( $name, '..' );

		return $is_valid ? [] : [ 'Plugin name must follow the Agent Plugins name pattern.' ];
	}

	/**
	 * @return string[]
	 */
	private function get_skill_errors( bool $is_public ): array {
		$errors = [];

		foreach ( $this->get_selected_skill_ids() as $skill_id ) {
			if ( ! Skill_Post::from_post_id( $skill_id ) ) {
				$errors[] = 'Each selected skill must reference an existing Agent Skill.';
			}
		}

		foreach ( $this->get_skills() as $skill ) {
			if ( $is_public && ! $skill->is_published() ) {
				$errors[] = sprintf( 'Selected skill %s is not published.', $skill->get_name() );
			}
		}

		return $errors;
	}

	/**
	 * @return string[]
	 */
	private function get_server_errors(): array {
		$errors = [];

		foreach ( $this->get_servers() as $server ) {
			$errors = array_merge( $errors, $server->get_errors() );
		}

		return $errors;
	}

	/**
	 * Errors about the package as a whole rather than about one of its parts.
	 *
	 * Skills and MCP servers are published side by side in the same package, so
	 * one name can only belong to one of them.
	 *
	 * @return string[]
	 */
	private function get_composition_errors(): array {
		$errors = [];

		if ( empty( $this->get_skills() ) && empty( $this->get_servers() ) ) {
			$errors[] = 'A plugin requires a skill or MCP server.';
		}

		$skill_names = array_map( fn ( Agent_Package $skill ): string => $skill->get_name(), $this->get_skills() );
		$server_names = array_map( fn ( MCP_Server $server ): string => $server->get_name(), $this->get_servers() );

		if ( count( array_unique( $skill_names ) ) !== count( $skill_names ) ) {
			$errors[] = 'Two selected skills have the same name.';
		}

		if ( count( array_unique( $server_names ) ) !== count( $server_names ) || array_intersect( $skill_names, $server_names ) ) {
			$errors[] = 'MCP server names must be unique.';
		}

		return $errors;
	}

	public function is_valid( bool $is_public = false ): bool {
		return empty( $this->get_errors( $is_public ) );
	}

	private function json( array $data ): string {
		return wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
	}

	public function get_plugin_json(): string {
		return $this->json( $this->get_manifest() );
	}

	public function get_mcp_json(): string {
		$data = [];

		foreach ( $this->get_servers() as $server ) {
			$data[ $server->get_name() ] = $server->to_array();
		}

		return $this->json(
			[
				'$schema' => self::MCP_SCHEMA,
				'mcpServers' => (object) $data,
			]
		);
	}

	/**
	 * @see Skill_Post::get_files()
	 */
	public function get_files(): Agent_Package_Files {
		$files = new Agent_Package_Files(
			[
				Package_File::from_callback( self::FILE_PLUGIN_JSON, fn (): string => $this->get_plugin_json() ),
				Package_File::from_callback( self::FILE_MCP_JSON, fn (): string => $this->get_mcp_json() ),
			]
		);

		foreach ( $this->get_skills() as $skill ) {
			$files->add_directory( sprintf( 'skills/%s', $skill->get_name() ), $skill->get_files() );
		}

		return $files;
	}

	public function get_hash(): string {
		$files = $this->get_files()->to_array();

		$parts = array_map(
			fn ( string $path, string $contents ): string => $path . "\0" . $contents,
			array_keys( $files ),
			$files
		);

		return hash( 'sha256', implode( "\0", $parts ) );
	}

	/**
	 * A package changes when any part of it changes, including a skill it
	 * bundles that was edited somewhere else entirely.
	 */
	public function get_last_modified(): ?int {
		$timestamps = [ parent::get_last_modified() ];

		foreach ( $this->get_skills() as $skill ) {
			$timestamps[] = $skill->get_last_modified();
		}

		$timestamps = array_filter( $timestamps );

		return ! empty( $timestamps ) ? max( $timestamps ) : null;
	}
}
