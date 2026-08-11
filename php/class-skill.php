<?php

namespace WPElevator\Agent_Pilot;

use WP_Post;
use WP_Block;

class Skill {

	public const BLOCK_NAME = 'agent-pilot/agent-skill';

	public const SCRIPT_BLOCK_NAME = 'agent-pilot/agent-skill-script';

	public const REFERENCE_BLOCK_NAME = 'agent-pilot/agent-skill-reference';

	public const ASSET_BLOCK_NAME = 'agent-pilot/agent-skill-asset';

	public const NAME_PATTERN = '[a-z0-9]+(?:-[a-z0-9]+)*';

	public const META_KEY_COMPATIBILITY = 'agent_pilot__compatibility';

	public const ALLOWED_BLOCKS = [
		'core/paragraph',
		'core/heading',
		'core/list',
		'core/list-item',
		'core/code',
		'core/preformatted',
		'core/quote',
		'core/separator',
		'core/image',
		self::SCRIPT_BLOCK_NAME,
		self::REFERENCE_BLOCK_NAME,
		self::ASSET_BLOCK_NAME,
	];

	private WP_Post $post;

	private array $blocks;

	public function __construct( WP_Post $post ) {
		$this->post = $post;
	}

	public static function from_post_id( int $post_id ): ?self {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post || Plugin::POST_TYPE !== $post->post_type ) {
			return null;
		}

		return new self( $post );
	}

	public function get_hash(): string {
		$parts = [
			// TODO: account for changes to linked posts in references and assets.
			$this->post->ID,
			$this->post->post_content,
			$this->get_last_modified() ?? '',
		];

		return md5( implode( '|', $parts ) );
	}

	public function get_post(): WP_Post {
		return $this->post;
	}

	public function get_id(): int {
		return $this->post->ID;
	}

	public function get_permalink(): string {
		if ( ! $this->is_published() ) {
			return get_preview_post_link( $this->post );
		}

		return get_permalink( $this->post );
	}

	public function is_archive(): bool {
		$resource_blocks = $this->get_blocks( [ self::SCRIPT_BLOCK_NAME, self::REFERENCE_BLOCK_NAME, self::ASSET_BLOCK_NAME ] );

		return ! empty( $resource_blocks );
	}

	public function get_name(): string {
		if ( empty( $this->post->post_name ) ) {
			return sprintf( 'agent-skill-%d-draft', $this->post->ID );
		}

		return $this->post->post_name;
	}

	public function get_title(): string {
		return $this->post->post_title;
	}

	public function get_description(): string {
		return trim( $this->normalize_newlines( wp_strip_all_tags( $this->post->post_excerpt ) ) );
	}

	public function get_compatibility(): string {
		$compatibility = get_post_meta( $this->post->ID, self::META_KEY_COMPATIBILITY, true );

		return trim( $this->normalize_newlines( wp_strip_all_tags( (string) $compatibility ) ) );
	}

	public function is_published(): bool {
		return 'publish' === $this->post->post_status;
	}

	public function get_last_modified(): ?int {
		// TODO: account for changes to linked references and assets.
		$timestamp = get_post_modified_time( 'U', true, $this->post );

		if ( ! $timestamp ) {
			$timestamp = get_post_time( 'U', true, $this->post );
		}

		if ( $timestamp ) {
			return (int) $timestamp;
		}

		return null;
	}

	public function get_front_matter(): array {
		return [
			'name' => $this->get_name(),
			'description' => $this->get_description(),
			'compatibility' => $this->get_compatibility(),
		];
	}

	public function get_files(): array {
		$files = [
			'SKILL.md' => $this->get_as_markdown(),
		];

		$scripts = array_filter( $this->get_scripts(), fn ( Skill_Script $script ): bool => $script->is_valid() );
		foreach ( $scripts as $script ) {
			$files[ $script->get_filename() ] = $script->get_content();
		}

		$assets = array_filter( $this->get_assets(), fn ( Skill_Asset $asset ): bool => $asset->is_valid() );
		foreach ( $assets as $asset ) {
			$files[ $asset->get_filename() ] = $asset->get_content();
		}

		$references = array_filter( $this->get_references(), fn ( Skill_Reference $reference ): bool => $reference->is_valid() );
		foreach ( $references as $reference ) {
			// TODO: Add the title of the post as heading.
			if ( 'md' === $reference->get_format() ) {
				$files[ $reference->get_filename() ] = Markdown::from_blocks( $reference->get_blocks() );
			} else {
				$files[ $reference->get_filename() ] = $reference->get_content(); // This is the default HTML.
			}
		}

		return $files;
	}

	public function get_as_markdown(): string {
		$front_matter = new Yaml( $this->get_front_matter() );

		return sprintf(
			"---\n%s\n---\n\n%s\n",
			$front_matter->get_yaml(),
			$this->get_body_markdown()
		);
	}

	public function get_block(): ?WP_Block {
		$skill_blocks = $this->get_blocks( [ self::BLOCK_NAME ] );

		return ! empty( $skill_blocks ) ? reset( $skill_blocks ) : null;
	}

	public function get_as_html(): string {
		$skill_block = $this->get_block();

		$content = [
			'title' => sprintf( '<h1>%s</h1>', esc_html( $this->get_title() ) ),
			'content' => $skill_block ? render_block( $skill_block->parsed_block ) : '',
		];

		return implode( "\n\n", $content );
	}

	private function get_body_markdown(): string {
		$content = [
			sprintf( '# %s', $this->get_title() ),
		];

		$skill_block = $this->get_block();
		if ( $skill_block && ! empty( $skill_block->parsed_block['innerBlocks'] ) ) {
			$content[] = Markdown::from_blocks( $skill_block->parsed_block['innerBlocks'] );
		}

		/**
		 * Include references.
		 */
		$references = array_unique(
			array_map(
				fn ( Skill_Reference $reference ): string => sprintf( '- %s', $reference->get_filename() ),
				array_filter( $this->get_references(), fn ( Skill_Reference $reference ): bool => $reference->is_valid() )
			)
		);

		$assets = array_unique(
			array_map(
				fn ( Skill_Asset $asset ): string => sprintf( '- %s', $asset->get_filename() ),
				array_filter( $this->get_assets(), fn ( Skill_Asset $asset ): bool => $asset->is_valid() )
			)
		);

		$scripts = array_unique(
			array_map(
				fn ( Skill_Script $script ): string => sprintf( '- %s', $script->get_filename() ),
				array_filter( $this->get_scripts(), fn ( Skill_Script $script ): bool => $script->is_valid() )
			)
		);

		if ( ! empty( $references ) || ! empty( $assets ) || ! empty( $scripts ) ) {
			$content[] = '---';
		}

		if ( ! empty( $references ) ) {
			$content[] = sprintf( '## %s', __( 'References', 'wpelevator-agent-pilot' ) );
			$content[] = implode( "\n", $references );
		}

		if ( ! empty( $assets ) ) {
			$content[] = sprintf( '## %s', __( 'Assets', 'wpelevator-agent-pilot' ) );
			$content[] = implode( "\n", $assets );
		}

		if ( ! empty( $scripts ) ) {
			$content[] = sprintf( '## %s', __( 'Scripts', 'wpelevator-agent-pilot' ) );
			$content[] = implode( "\n", $scripts );
		}

		return $this->normalize_newlines( implode( "\n\n", $content ) );
	}

	private function get_all_blocks( ?array $blocks = [] ): array {
		$all = [];

		foreach ( $blocks as $block ) {
			$all[] = $block;

			if ( ! empty( $block['innerBlocks'] ) ) {
				$all = array_merge( $all, $this->get_all_blocks( $block['innerBlocks'] ) );
			}
		}

		return $all;
	}

	private function get_blocks( ?array $names = [] ): array {
		if ( ! isset( $this->blocks ) ) {
			$this->blocks = array_map(
				fn ( array $block ): WP_Block => new WP_Block( $block ),
				$this->get_all_blocks( parse_blocks( $this->post->post_content ) )
			);
		}

		if ( empty( $names ) ) {
			return $this->blocks;
		}

		return array_filter(
			$this->blocks,
			function ( WP_Block $block ) use ( $names ): bool {
				return in_array( $block->name, $names, true );
			}
		);
	}

	/**
	 * @return Skill_Reference[]
	 */
	public function get_references(): array {
		return array_map(
			fn ( WP_Block $block ): Skill_Reference => new Skill_Reference( $block ),
			$this->get_blocks( [ self::REFERENCE_BLOCK_NAME ] )
		);
	}

	/**
	 * @return Skill_Script[]
	 */
	public function get_scripts(): array {
		return array_map(
			fn ( WP_Block $block ): Skill_Script => new Skill_Script( $block ),
			$this->get_blocks( [ self::SCRIPT_BLOCK_NAME ] )
		);
	}

	/**
	 * @return Skill_Asset[]
	 */
	public function get_assets(): array {
		return array_map(
			fn ( WP_Block $block ): Skill_Asset => new Skill_Asset( $block ),
			$this->get_blocks( [ self::ASSET_BLOCK_NAME ] )
		);
	}

	private function normalize_newlines( string $value ): string {
		return str_replace( [ "\r\n", "\r" ], "\n", $value );
	}
}
