<?php
/**
 * Render the Agent Skill block.
 *
 * @global array $attributes The block attributes.
 * @global string $content The block content.
 * @global WP_Block $block The block instance.
 */

namespace WPElevator\Agent_Pilot;

use WP_Block_Type_Registry;

$post_id = ! empty( $block->context['postId'] ) ? (int) $block->context['postId'] : get_the_ID();
$skill = Skill::from_post_id( $post_id );

if ( ! $skill ) {
	return '';
}

$markup = sprintf(
	'<pre class="wp-block-code"><code>%s</code></pre>',
	esc_html( $skill->get_as_markdown() )
);

if ( WP_Block_Type_Registry::get_instance()->get_registered( 'core/code' ) ) {
	$markup = render_block(
		[
			'blockName' => 'core/code',
			'attrs' => [],
			'innerBlocks' => [],
			'innerHTML' => $markup,
			'innerContent' => [ $markup ],
		]
	);
}

printf(
	'<div %1$s>%2$s</div>',
	get_block_wrapper_attributes(),
	$markup
);
