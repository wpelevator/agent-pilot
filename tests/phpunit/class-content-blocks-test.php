<?php

namespace WPElevator\Agent_Pilot_Tests;

use WPElevator\Agent_Pilot\Content_Blocks;

class Content_Blocks_Test extends \WP_UnitTestCase {

	public function test_flattens_and_filters_parsed_blocks() {
		$content_blocks = new Content_Blocks(
			parse_blocks(
				'<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph --></div><!-- /wp:group -->'
			)
		);

		$blocks = $content_blocks->get_blocks();
		$this->assertSame( [ 'core/group', 'core/paragraph' ], array_column( $blocks, 'name' ), 'Content blocks should include nested blocks in document order.' );

		$paragraphs = $content_blocks->get_blocks( [ 'core/paragraph' ] );
		$this->assertCount( 1, $paragraphs, 'Filtering should return only blocks with requested names.' );
		$this->assertContainsOnlyInstancesOf( \WP_Block::class, $paragraphs, 'Parsed blocks should be represented as schema-aware WP_Block instances.' );
	}

	public function test_creates_blocks_from_content() {
		$content_blocks = Content_Blocks::from_content( '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->' );

		$this->assertSame( [ 'core/paragraph' ], array_column( $content_blocks->get_blocks(), 'name' ), 'The static maker should parse serialized block content.' );
	}
}
