<?php

namespace WPElevator\Agent_Pilot;

use WP_Block;
use WP_Post;

class Agent_Plugin {
	public const BLOCK_NAME_PLUGIN = 'agent-pilot/agent-plugin';
	public const BLOCK_NAME_SKILL = 'agent-pilot/agent-plugin-skill';
	public const BLOCK_NAME_MCP = 'agent-pilot/agent-plugin-mcp-server';

	public const SCHEMA = 'https://agent-plugins.org/schemas/1.0.0/plugin.schema.json';
	public const MCP_SCHEMA = 'https://agent-plugins.org/schemas/1.0.0/mcp.schema.json';

	private WP_Post $post;
	private Skills $skills;
	private Content_Blocks $content_blocks;

	public function __construct( WP_Post $post, Skills $skills ) {
		$this->post = $post;
		$this->skills = $skills;
	}

	private function get_blocks( array $names = [] ): array {
		if ( ! isset( $this->content_blocks ) ) {
			$this->content_blocks = Content_Blocks::from_content( $this->post->post_content );
		}

		return $this->content_blocks->get_blocks( $names );
	}

	public function get_id(): int {
		return $this->post->ID;
	}

	public function get_post(): WP_Post {
		return $this->post;
	}

	public function is_published(): bool {
		return 'publish' === $this->post->post_status;
	}

	public function get_name(): string {
		if ( empty( $this->post->post_name ) ) {
			return sprintf( 'agent-plugin-%d-draft', $this->post->ID );
		}

		return $this->post->post_name;
	}

	public function get_permalink(): string {
		return $this->is_published() ? get_permalink( $this->post ) : get_preview_post_link( $this->post );
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
		foreach ( [ 'license' ] as $key ) {
			if ( ! empty( $attributes[ $key ] ) && is_string( $attributes[ $key ] ) ) {
				$manifest[ $key ] = trim( $attributes[ $key ] );
			}
		}
		$description = trim( wp_strip_all_tags( $this->post->post_excerpt ) );
		if ( '' !== $description ) {
			$manifest['description'] = $description;
		}
		foreach ( [ 'author', 'extensions' ] as $key ) {
			if ( ! empty( $attributes[ $key ] ) && is_array( $attributes[ $key ] ) ) {
				$manifest[ $key ] = $attributes[ $key ];
			}
		}
		return $manifest;
	}

	public function get_skills(): array {
		$skills = [];
		foreach ( $this->get_blocks( [ self::BLOCK_NAME_SKILL ] ) as $block ) {
			if ( empty( $block->attributes['skillId'] ) ) {
				continue;
			}

			$skill = Skill::from_post_id( (int) $block->attributes['skillId'] );
			if ( $skill ) {
				$skills[ $skill->get_id() ] = $skill;
			}
		}
		return $skills;
	}

	public function get_servers(): array {
		$servers = array_map(
			fn ( WP_Block $block ): MCP_Server => new MCP_Server( $block ),
			$this->get_blocks( [ self::BLOCK_NAME_MCP ] )
		);

		usort( $servers, fn( MCP_Server $a, MCP_Server $b ): int => strcmp( $a->get_name(), $b->get_name() ) );

		return $servers;
	}

	public function get_errors( bool $is_public = false ): array {
		$errors = [];
		$name = $this->get_name();
		if ( ! preg_match( '/^[a-z0-9](?:[a-z0-9.-]{0,62}[a-z0-9])?$/', $name ) || false !== strpos( $name, '--' ) || false !== strpos( $name, '..' ) ) {
			$errors[] = 'Plugin name must follow the Agent Plugins name pattern.';
		}
		foreach ( $this->get_blocks( [ self::BLOCK_NAME_SKILL ] ) as $block ) {
			if ( ! Skill::from_post_id( (int) ( $block->attributes['skillId'] ?? 0 ) ) ) {
				$errors[] = 'Each selected skill must reference an existing Agent Skill.';
			}
		}
		$names = [];
		foreach ( $this->get_skills() as $skill ) {
			if ( $is_public && ! $skill->is_published() ) {
				$errors[] = sprintf( 'Selected skill %s is not published.', $skill->get_name() );
			}
			if ( isset( $names[ $skill->get_name() ] ) ) {
				$errors[] = 'Two selected skills have the same name.';
			}
			$names[ $skill->get_name() ] = true;
		}
		foreach ( $this->get_servers() as $server ) {
			$errors = array_merge( $errors, $server->get_errors() );
			if ( isset( $names[ $server->get_name() ] ) ) {
				$errors[] = 'MCP server names must be unique.';
			}
			$names[ $server->get_name() ] = true;
		}
		if ( empty( $this->get_skills() ) && empty( $this->get_servers() ) ) {
			$errors[] = 'A plugin requires a skill or MCP server.';
		}
		return array_values( array_unique( $errors ) );
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

	public function get_files(): array {
		$files = [
			'plugin.json' => $this->get_plugin_json(),
			'mcp.json' => $this->get_mcp_json(),
		];

		foreach ( $this->get_skills() as $skill ) {
			foreach ( $skill->get_files() as $path => $content ) {
				$files[ 'skills/' . $skill->get_name() . '/' . ltrim( $path, '/' ) ] = $content;
			}
		}

		return $files;
	}

	public function get_hash(): string {
		$files = $this->get_files();

		return hash( 'sha256', implode( "\0", array_map( fn( $path, $contents ): string => $path . "\0" . $contents, array_keys( $files ), $files ) ) );
	}

	public function get_last_modified(): int {
		$timestamps = [ (int) get_post_modified_time( 'U', true, $this->post ) ];
		foreach ( $this->get_skills() as $skill ) {
			$last_modified = $skill->get_last_modified();
			$timestamps[] = $last_modified ? $last_modified : 0;
		}
		return max( $timestamps );
	}
}
