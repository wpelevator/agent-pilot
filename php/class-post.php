<?php

namespace WPElevator\Agent_Pilot;

use WP_Post;

/**
 * A WordPress post, seen as the blocks it is composed of.
 *
 * Everything a skill and an Agent Plugin share by virtue of being posts lives
 * here: identity, the blocks, the name each publishes itself under, where it is
 * served from, when it last changed, and who is allowed to see it. What they
 * publish is not here at all — that is the `Agent_Package` contract, which a
 * subclass implements and which something that was never a post could implement
 * just as well.
 */
abstract class Post {

	protected WP_Post $post;

	private Content_Blocks $content_blocks;

	public function __construct( WP_Post $post ) {
		$this->post = $post;
	}

	/**
	 * The name to publish under while the post has no slug of its own.
	 *
	 * WordPress assigns a slug when a post is first published, so an unpublished
	 * package has no name yet. It still needs one that is stable and unique
	 * for as long as it exists, because it is the name a listing shows and the
	 * one its collection resolves back into this post.
	 */
	abstract protected function get_draft_name(): string;

	protected function get_blocks( array $names = [] ): array {
		if ( ! isset( $this->content_blocks ) ) {
			$this->content_blocks = Content_Blocks::from_content( $this->post->post_content );
		}

		return $this->content_blocks->get_blocks( $names );
	}

	public function get_post(): WP_Post {
		return $this->post;
	}

	public function get_id(): int {
		return $this->post->ID;
	}

	public function get_name(): string {
		if ( empty( $this->post->post_name ) ) {
			return $this->get_draft_name();
		}

		return $this->post->post_name;
	}

	public function get_title(): string {
		return $this->post->post_title;
	}

	public function get_description(): string {
		return trim( $this->normalize_newlines( wp_strip_all_tags( $this->post->post_excerpt ) ) );
	}

	public function is_published(): bool {
		return 'publish' === $this->post->post_status;
	}

	/**
	 * Whether the current user is allowed to see this package.
	 *
	 * A published one is readable by anyone, because the same content is already
	 * served without authentication from its permalink and from the well-known
	 * discovery index. Everything else falls back to the capabilities WordPress
	 * maps for the post type, so an author reaches their own unpublished work
	 * and a subscriber does not.
	 */
	public function can_read(): bool {
		return $this->is_published() || current_user_can( 'read_post', $this->post->ID );
	}

	public function get_last_modified(): ?int {
		$timestamp = get_post_modified_time( 'U', true, $this->post );

		if ( ! $timestamp ) {
			$timestamp = get_post_time( 'U', true, $this->post );
		}

		if ( $timestamp ) {
			return (int) $timestamp;
		}

		return null;
	}

	public function get_permalink(): string {
		if ( ! $this->is_published() ) {
			return get_preview_post_link( $this->post );
		}

		return get_permalink( $this->post );
	}

	protected function normalize_newlines( string $value ): string {
		return str_replace( [ "\r\n", "\r" ], "\n", $value );
	}
}
