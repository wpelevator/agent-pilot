<?php

namespace WPElevator\Agent_Pilot;

use WP_Block;

class Content_Blocks {

	private array $blocks;

	public static function from_content( string $content ): self {
		return new self( parse_blocks( $content ) );
	}

	public function __construct( array $blocks ) {
		$this->blocks = array_map(
			fn ( array $block ): WP_Block => new WP_Block( $block ),
			$this->get_all_blocks( $blocks )
		);
	}

	private function get_all_blocks( array $blocks ): array {
		$all = [];

		foreach ( $blocks as $block ) {
			$all[] = $block;

			if ( ! empty( $block['innerBlocks'] ) ) {
				$all = array_merge( $all, $this->get_all_blocks( $block['innerBlocks'] ) );
			}
		}

		return $all;
	}

	public function get_blocks( array $names = [] ): array {
		if ( empty( $names ) ) {
			return $this->blocks;
		}

		return array_filter(
			$this->blocks,
			fn ( WP_Block $block ): bool => in_array( $block->name, $names, true )
		);
	}
}
