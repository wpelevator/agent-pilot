<?php

namespace WPElevator\Agent_Pilot;

use WP_Post;

class Skill_Reference extends Skill_Resource {

	public function get_filename(): ?string {
		$filename = (string) $this->get_attribute( 'fileName' );
		$format = $this->get_format();

		if ( '' !== $filename && ! empty( $format ) ) {
			// Account for extension being part of filename.
			if ( false !== strpos( $filename, '.' ) ) {
				$filename = pathinfo( $filename, PATHINFO_FILENAME );
			}

			$filename = sanitize_file_name( sprintf( '%s.%s', $filename, $format ) );

			if ( '' !== $filename ) {
				return sprintf( 'references/%s', $filename );
			}
		}

		return null;
	}

	public function get_reference_post(): ?WP_Post {
		$post_id = $this->get_attribute( 'postId' );

		if ( $post_id ) {
			return get_post( (int) $post_id );
		}

		return null;
	}

	public function get_blocks(): array {
		$reference_post = $this->get_reference_post();

		if ( $reference_post ) {
			return parse_blocks( $reference_post->post_content );
		}

		return $this->block->parsed_block['innerBlocks'] ?? [];
	}

	public function get_content(): ?string {
		$content = array_map(
			fn( $block ) => render_block( $block ),
			$this->get_blocks()
		);

		$content = trim( implode( "\n\n", array_filter( $content ) ) );

		return '' !== $content ? $content : null;
	}
}
