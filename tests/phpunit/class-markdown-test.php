<?php

namespace WPElevator\Agent_Pilot_Tests;

use WPElevator\Agent_Pilot\Markdown;
use WPElevator\Agent_Pilot\Skill;

class Markdown_Test extends \WP_UnitTestCase {

	public function test_converts_authored_blocks_to_markdown() {
		$markdown = Markdown::from_blocks(
			parse_blocks(
				implode(
					'',
					[
						'<!-- wp:heading {"level":3} --><h3 class="wp-block-heading">Review process</h3><!-- /wp:heading -->',
						'<!-- wp:paragraph --><p>Inspect <strong>changes</strong> in <code>src</code> and <a href="https://example.org/docs">read the docs</a>.</p><!-- /wp:paragraph -->',
						'<!-- wp:separator --><hr class="wp-block-separator"/><!-- /wp:separator -->',
						'<!-- wp:code --><pre class="wp-block-code"><code>composer test</code></pre><!-- /wp:code -->',
					]
				)
			)
		);

		$this->assertSame(
			"### Review process\n\nInspect **changes** in `src` and [read the docs](https://example.org/docs).\n\n---\n\n```\ncomposer test\n```",
			$markdown,
			'Supported blocks should convert to Markdown with a single blank line between them.'
		);
	}

	public function test_converts_ordered_and_nested_lists_from_blocks() {
		$markdown = Markdown::from_blocks(
			parse_blocks(
				implode(
					'',
					[
						'<!-- wp:list {"ordered":true,"start":3} --><ol class="wp-block-list" start="3">',
						'<!-- wp:list-item --><li>Third</li><!-- /wp:list-item -->',
						'<!-- wp:list-item --><li>Fourth',
						'<!-- wp:list --><ul class="wp-block-list"><!-- wp:list-item --><li>Nested</li><!-- /wp:list-item --></ul><!-- /wp:list -->',
						'</li><!-- /wp:list-item -->',
						'</ol><!-- /wp:list -->',
					]
				)
			)
		);

		$this->assertSame(
			"3. Third\n4. Fourth\n  - Nested",
			$markdown,
			'Ordered lists should honour their start index and indent nested lists under their parent item.'
		);
	}

	public function test_converts_child_blocks_without_repeating_list_items() {
		$blocks = parse_blocks(
			implode(
				'',
				[
					sprintf( '<!-- wp:%s --><div>', Skill::BLOCK_NAME ),
					'<!-- wp:paragraph --><p>Instructions.</p><!-- /wp:paragraph -->',
					'<!-- wp:list --><ul class="wp-block-list">',
					'<!-- wp:list-item --><li>First</li><!-- /wp:list-item -->',
					'<!-- wp:list-item --><li>Second</li><!-- /wp:list-item -->',
					'</ul><!-- /wp:list -->',
					sprintf( '<!-- wp:%s {"fileName":"guide","format":"md"} --><div><p>Reference body.</p></div><!-- /wp:%1$s -->', Skill::REFERENCE_BLOCK_NAME ),
					sprintf( '</div><!-- /wp:%s -->', Skill::BLOCK_NAME ),
				]
			)
		);
		$markdown = Markdown::from_blocks(
			$blocks[0]['innerBlocks']
		);

		$this->assertSame(
			"Instructions.\n\n- First\n- Second",
			$markdown,
			'Allowlisted child blocks should render once without inlining resource block contents.'
		);
	}

	public function test_widens_the_fence_for_code_that_already_contains_one() {
		$markdown = Markdown::from_blocks(
			parse_blocks( '<!-- wp:code --><pre class="wp-block-code"><code>```\nnested\n```</code></pre><!-- /wp:code -->' )
		);

		$this->assertStringStartsWith( "````\n", $markdown, 'Code containing a fence should be wrapped in a wider fence.' );
		$this->assertStringEndsWith( "\n````", $markdown, 'The closing fence should match the widened opening fence.' );
	}

	public function test_converts_image_blocks_to_markdown_images() {
		$markdown = Markdown::from_blocks(
			parse_blocks( '<!-- wp:image {"id":123,"sizeSlug":"large"} --><figure class="wp-block-image size-large"><img src="https://example.org/wp-content/uploads/diagram.png" alt="Architecture diagram" class="wp-image-123"/><figcaption>Ignored caption.</figcaption></figure><!-- /wp:image -->' )
		);

		$this->assertSame(
			'![Architecture diagram](https://example.org/wp-content/uploads/diagram.png)',
			$markdown,
			'Image blocks should export a Markdown image linked to the saved source file.'
		);
	}

	public function test_preserves_quote_citations_from_blocks() {
		$markdown = Markdown::from_blocks(
			parse_blocks( '<!-- wp:quote --><blockquote class="wp-block-quote"><!-- wp:paragraph --><p>Check every change.</p><!-- /wp:paragraph --><cite>Review policy</cite></blockquote><!-- /wp:quote -->' )
		);

		$this->assertSame( "> Check every change.\n> \n> — Review policy", $markdown, 'Quote blocks should keep their citation below the quoted text.' );
	}

	public function test_ignores_blocks_it_cannot_represent() {
		$markdown = Markdown::from_blocks(
			parse_blocks(
				implode(
					'',
					[
						'<!-- wp:paragraph --><p>Kept.</p><!-- /wp:paragraph -->',
						"\n\n",
						sprintf( '<!-- wp:%s {"fileName":"hello.sh"} --><pre><code>echo hello</code></pre><!-- /wp:%1$s -->', Skill::SCRIPT_BLOCK_NAME ),
						'<!-- wp:table --><figure class="wp-block-table"><table><tr><td>Cell</td></tr></table></figure><!-- /wp:table -->',
					]
				)
			)
		);

		$this->assertSame( 'Kept.', $markdown, 'Unsupported blocks and the whitespace between blocks should not leave blank lines behind.' );
	}

	public function test_converts_rendered_html_to_markdown() {
		$markdown = Markdown::from_html(
			implode(
				"\n",
				[
					'<h2>Guide</h2>',
					'<p>Use <strong>blocks</strong> and <a href="https://example.org">links</a>.</p>',
					'<ul><li>First</li><li>Second<ul><li>Nested</li></ul></li></ul>',
					'<ol start="3"><li>Third</li></ol>',
					'<pre><code>echo &quot;hi&quot;</code></pre>',
					'<blockquote><p>Quoted.</p><cite>Policy</cite></blockquote>',
					'<hr />',
					'<figure><img src="https://example.org/a.png" alt="Diagram" /></figure>',
				]
			)
		);

		$this->assertSame(
			implode(
				"\n",
				[
					'## Guide',
					'',
					'Use **blocks** and [links](https://example.org).',
					'',
					'- First',
					'- Second',
					'  - Nested',
					'',
					'3. Third',
					'',
					'```',
					'echo "hi"',
					'```',
					'',
					'> Quoted.',
					'> ',
					'> — Policy',
					'',
					'---',
					'',
					'![Diagram](https://example.org/a.png)',
				]
			),
			$markdown,
			'Rendered HTML should convert to the same Markdown vocabulary as authored blocks.'
		);
	}

	public function test_converts_inline_html_without_block_structure() {
		$this->assertSame(
			'Read **this** and *that* in `code`.',
			Markdown::from_inline_html( 'Read <strong>this</strong> and <em>that</em> in <code>code</code>.' ),
			'Inline conversion should keep emphasis without introducing block spacing.'
		);
		$this->assertSame(
			"First\nSecond",
			Markdown::from_inline_html( 'First<br>Second' ),
			'Line breaks should become real newlines.'
		);
	}

	public function test_reduces_html_to_plain_text_with_decoded_entities() {
		$this->assertSame(
			'Tom & Jerry say "hi"',
			Markdown::to_plain_text( '<p>Tom &amp; Jerry say &quot;hi&quot;</p>' ),
			'Plain text should drop markup and decode HTML entities.'
		);
	}
}
