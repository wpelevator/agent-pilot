<?php

namespace WPElevator\Agent_Pilot;

use WP_Post;
use WP_Block;

class Skill_Post extends Post implements Agent_Package {

	public const BLOCK_NAME = 'agent-pilot/agent-skill';

	public const SCRIPT_BLOCK_NAME = 'agent-pilot/agent-skill-script';

	public const REFERENCE_BLOCK_NAME = 'agent-pilot/agent-skill-reference';

	public const ASSET_BLOCK_NAME = 'agent-pilot/agent-skill-asset';

	public const NAME_PATTERN = '[a-z0-9]+(?:-[a-z0-9]+)*';

	public const FILE_SKILL_MD = 'SKILL.md';

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

	public static function from_post_id( int $post_id ): ?self {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post || Plugin::POST_TYPE_AGENT_SKILL !== $post->post_type ) {
			return null;
		}

		return new self( $post );
	}

	public function get_hash(): string {
		$parts = [
			$this->post->ID,
			$this->post->post_content,
			$this->get_last_modified() ?? '',
		];

		return md5( implode( '|', $parts ) );
	}

	/**
	 * A skill changes when its own post changes, and also when a post or an
	 * attachment it publishes the content of changes somewhere else entirely.
	 *
	 * Both this and the hash built on it are cache keys for the generated files,
	 * so leaving linked content out of them served a stale archive after a
	 * reference was edited. An attachment counts as changed when its post is
	 * updated, which replacing the file through WordPress does; overwriting the
	 * bytes underneath it without touching the attachment does not.
	 */
	public function get_last_modified(): ?int {
		$timestamps = [ parent::get_last_modified() ];

		foreach ( $this->get_linked_post_ids() as $post_id ) {
			$timestamps[] = (int) get_post_modified_time( 'U', true, $post_id );
		}

		$timestamps = array_filter( $timestamps );

		return ! empty( $timestamps ) ? max( $timestamps ) : null;
	}

	/**
	 * The posts whose content this skill publishes as its own files.
	 *
	 * @return int[]
	 */
	private function get_linked_post_ids(): array {
		$ids = [];

		$references = array_filter( $this->get_references(), fn ( Skill_Reference $reference ): bool => $reference->is_valid() );
		foreach ( $references as $reference ) {
			$reference_post = $reference->get_reference_post();

			if ( $reference_post ) {
				$ids[] = $reference_post->ID;
			}
		}

		$assets = array_filter( $this->get_assets(), fn ( Skill_Asset $asset ): bool => $asset->is_valid() );
		foreach ( $assets as $asset ) {
			$ids[] = (int) $asset->get_attachment_id();
		}

		return array_values( array_unique( array_filter( $ids ) ) );
	}

	public function is_archive(): bool {
		$resource_blocks = $this->get_blocks( [ self::SCRIPT_BLOCK_NAME, self::REFERENCE_BLOCK_NAME, self::ASSET_BLOCK_NAME ] );

		return ! empty( $resource_blocks );
	}

	protected function get_draft_name(): string {
		return sprintf( '%s-%d-draft', Plugin::PERMALINK_PREFIX_AGENT_SKILL, $this->post->ID );
	}

	public function get_compatibility(): string {
		$compatibility = get_post_meta( $this->post->ID, self::META_KEY_COMPATIBILITY, true );

		return trim( $this->normalize_newlines( wp_strip_all_tags( (string) $compatibility ) ) );
	}

	public function get_front_matter(): array {
		return [
			'name' => $this->get_name(),
			'description' => $this->get_description(),
			'compatibility' => $this->get_compatibility(),
		];
	}

	/**
	 * Every path is known from the blocks alone, so a listing can name the files
	 * of every skill on a site without any of them being generated. Only reading
	 * one renders its blocks or reads its attachment.
	 */
	public function get_files(): Agent_Package_Files {
		$files = new Agent_Package_Files(
			[
				Package_File::from_callback( self::FILE_SKILL_MD, fn (): string => $this->get_as_markdown() ),
			]
		);

		$scripts = array_filter( $this->get_scripts(), fn ( Skill_Script $script ): bool => $script->is_valid() );
		foreach ( $scripts as $script ) {
			$files->add(
				Package_File::from_callback(
					(string) $script->get_filename(),
					fn (): string => (string) $script->get_content()
				)
			);
		}

		$assets = array_filter( $this->get_assets(), fn ( Skill_Asset $asset ): bool => $asset->is_valid() );
		foreach ( $assets as $asset ) {
			$files->add(
				Package_File::from_asset(
					(string) $asset->get_filename(),
					[ $asset, 'get_content' ],
					(string) $asset->get_attachment_url(),
					(string) get_post_mime_type( (int) $asset->get_attachment_id() )
				)
			);
		}

		$references = array_filter( $this->get_references(), fn ( Skill_Reference $reference ): bool => $reference->is_valid() );
		foreach ( $references as $reference ) {
			// TODO: Add the title of the post as heading.
			$files->add(
				Package_File::from_callback(
					(string) $reference->get_filename(),
					fn (): string => 'md' === $reference->get_format()
						? Markdown::from_blocks( $reference->get_blocks() )
						: (string) $reference->get_content() // This is the default HTML.
				)
			);
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
}
