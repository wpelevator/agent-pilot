<?php

namespace WPElevator\Agent_Pilot;

class Skill_Asset extends Skill_Resource {

	public function get_blocks(): array {
		return []; // There is no inner content.
	}

	public function get_filename(): ?string {
		$filename = $this->get_attribute( 'fileName' );

		if ( isset( $filename ) && '' !== $filename ) {
			return sprintf( 'assets/%s', ltrim( $filename, '/' ) );
		}

		return null;
	}

	public function get_attachment_url(): ?string {
		$attachment_id = $this->get_attachment_id();

		if ( $attachment_id ) {
			return wp_get_attachment_url( $attachment_id );
		}

		return null;
	}

	public function get_attachment_id(): ?int {
		$attachment_id = (int) $this->get_attribute( 'attachmentId' );

		if ( $attachment_id ) {
			return $attachment_id;
		}

		return null;
	}

	public function is_valid(): bool {
		return ! empty( $this->get_attachment_id() );
	}

	public function get_content(): ?string {
		$attachment_id = $this->get_attachment_id();

		if ( $attachment_id ) {
			$file_path = get_attached_file( $attachment_id );

			if ( $file_path && is_readable( $file_path ) ) {
				return file_get_contents( $file_path );
			}
		}

		return null;
	}
}
