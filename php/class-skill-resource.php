<?php

namespace WPElevator\Agent_Pilot;

use WP_Block;

abstract class Skill_Resource {

	protected WP_Block $block;

	public function __construct( WP_Block $block ) {
		$this->block = $block;
	}

	abstract public function get_filename(): ?string;

	abstract public function get_content(): ?string;

	public function is_valid(): bool {
		return ! empty( $this->get_filename() );
	}

	public function get_format(): ?string {
		$format = $this->get_attribute( 'format' );

		if ( ! empty( $format ) ) {
			return $format;
		}

		return null;
	}

	public function get_attribute( string $name ) {
		return $this->block->attributes[ $name ] ?? null;
	}

	public function get_block(): WP_Block {
		return $this->block;
	}

	public function get_blocks(): array {
		return (array) $this->block->inner_blocks;
	}
}
