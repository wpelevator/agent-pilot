<?php

namespace WPElevator\Agent_Pilot;

class Skill_Script extends Skill_Resource {

	public function get_filename(): ?string {
		$filename = sanitize_file_name( (string) $this->get_attribute( 'fileName' ) );

		if ( '' !== $filename ) {
			return sprintf( 'scripts/%s', $filename );
		}

		return null;
	}

	/**
	 * The script content is stored as the text of the saved <pre><code> markup
	 * rather than as a block attribute, because the block sources it from the
	 * <code> element, and WordPress resolves sourced attributes only in JavaScript.
	 */
	public function get_content(): ?string {
		if ( preg_match( '#<pre[^>]*>\s*<code[^>]*>(.*)</code>\s*</pre>#is', $this->block->inner_html, $matches ) ) {
			return html_entity_decode( $matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}

		return null;
	}
}
