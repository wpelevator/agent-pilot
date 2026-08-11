<?php

namespace WPElevator\Agent_Pilot_Tests;

use WPElevator\Agent_Pilot\Plugin;
use WPElevator\Agent_Pilot\Skill;

class Skill_Test extends \WP_UnitTestCase {

	public function set_up() {
		parent::set_up();

		Plugin::action_register_post_type();
	}

	public function test_resolves_the_regular_permalink_for_published_skills() {
		$post_id = self::factory()->post->create(
			[
				'post_type' => Plugin::POST_TYPE,
				'post_name' => 'example-skill',
				'post_status' => 'publish',
			]
		);
		$skill = new Skill( get_post( $post_id ) );

		$this->assertTrue( $skill->is_published(), 'Published post status should enable public distribution.' );
		$this->assertSame( get_permalink( $post_id ), $skill->get_permalink(), 'A published skill should return its regular WordPress permalink.' );
	}

	public function test_resolves_a_preview_permalink_for_unpublished_skills() {
		$post_id = self::factory()->post->create(
			[
				'post_type' => Plugin::POST_TYPE,
				'post_name' => 'draft-skill',
				'post_status' => 'draft',
			]
		);
		$skill = new Skill( get_post( $post_id ) );

		$this->assertFalse( $skill->is_published(), 'Draft skills should not be treated as publicly distributable.' );
		$this->assertSame( get_preview_post_link( get_post( $post_id ) ), $skill->get_permalink(), 'Unpublished skills should be reachable through their preview link.' );
	}

	public function test_encapsulates_post_fields_and_serializes_blocks_to_markdown() {
		$post_id = self::factory()->post->create(
			[
				'post_type' => Plugin::POST_TYPE,
				'post_name' => 'code-review',
				'post_title' => 'Code Review',
				'post_excerpt' => 'Review "code" safely.',
				'post_status' => 'publish',
				'post_content' => implode(
					'',
					[
						'<!-- wp:agent-pilot/agent-skill --><div class="wp-block-agent-pilot-agent-skill">',
						'<!-- wp:heading {"level":2} --><h2 class="wp-block-heading">Review process</h2><!-- /wp:heading -->',
						'<!-- wp:paragraph --><p>Inspect <strong>changes</strong>.</p><!-- /wp:paragraph -->',
						'<!-- wp:list --><ul class="wp-block-list"><!-- wp:list-item --><li>Find bugs</li><!-- /wp:list-item --><!-- wp:list-item --><li>Report risks</li><!-- /wp:list-item --></ul><!-- /wp:list -->',
						'<!-- wp:code --><pre class="wp-block-code"><code>composer test</code></pre><!-- /wp:code -->',
						'</div><!-- /wp:agent-pilot/agent-skill -->',
					]
				),
			]
		);

		$skill = Skill::from_post_id( $post_id );

		$this->assertInstanceOf( Skill::class, $skill, 'Agent skill posts should map to Skill domain objects.' );
		$this->assertSame( $post_id, $skill->get_id(), 'Skill identity should track the underlying post ID.' );
		$this->assertSame( 'code-review', $skill->get_name(), 'Skill identity should come from the post slug.' );
		$this->assertSame( 'Code Review', $skill->get_title(), 'Skill titles should come from the post title.' );
		$this->assertSame( 'Review "code" safely.', $skill->get_description(), 'Skill description should come from the post excerpt.' );
		$this->assertSame(
			"---\nname: code-review\ndescription: \"Review \\\"code\\\" safely.\"\ncompatibility: \"\"\n---\n\n# Code Review\n\n## Review process\n\nInspect **changes**.\n\n- Find bugs\n- Report risks\n\n```\ncomposer test\n```\n",
			$skill->get_as_markdown(),
			'Supported editor blocks should export as deterministic Markdown with front matter quoted only when required.'
		);
	}

	public function test_includes_compatibility_frontmatter_from_prefixed_post_meta() {
		$post_id = self::factory()->post->create(
			[
				'post_type' => Plugin::POST_TYPE,
				'post_name' => 'docker-skill',
				'post_excerpt' => 'Run Docker commands.',
				'post_content' => '<!-- wp:paragraph --><p>Use Docker.</p><!-- /wp:paragraph -->',
			]
		);
		update_post_meta( $post_id, Skill::META_KEY_COMPATIBILITY, 'Requires Docker and WP-CLI.' );

		$skill = Skill::from_post_id( $post_id );

		$this->assertSame( 'agent_pilot__compatibility', Skill::META_KEY_COMPATIBILITY, 'The compatibility meta key should use the plugin double-underscore prefix signature.' );
		$this->assertSame( 'Requires Docker and WP-CLI.', $skill->get_compatibility(), 'Skill compatibility should come from the prefixed post meta value.' );
		$this->assertSame(
			[
				'name' => 'docker-skill',
				'description' => 'Run Docker commands.',
				'compatibility' => 'Requires Docker and WP-CLI.',
			],
			$skill->get_front_matter(),
			'Skill frontmatter should expose the published identity fields.'
		);
		$this->assertStringContainsString( "compatibility: Requires Docker and WP-CLI.\n", $skill->get_as_markdown(), 'Compatibility metadata should be exported as plain unquoted SKILL.md frontmatter.' );
	}

	public function test_rejects_other_post_types() {
		$post_id = self::factory()->post->create();

		$this->assertNull( Skill::from_post_id( $post_id ), 'Only agent_skill posts should map to Skill domain objects.' );
	}

	public function test_last_modified_time_tracks_the_post_modification_time() {
		$post_id = self::factory()->post->create(
			[
				'post_type' => Plugin::POST_TYPE,
				'post_name' => 'timed-skill',
				'post_date' => '2024-01-05 10:00:00',
			]
		);
		$skill = Skill::from_post_id( $post_id );

		$this->assertSame(
			(int) get_post_modified_time( 'U', true, $skill->get_post() ),
			$skill->get_last_modified(),
			'The skill last modified timestamp should come from the GMT post modification time.'
		);
		$this->assertSame(
			strtotime( '2024-01-05 10:00:00+00:00' ),
			$skill->get_last_modified(),
			'Skills that have not been edited since creation should reuse their creation time.'
		);
	}

	public function test_normalizes_resource_filenames_to_safe_archive_paths() {
		$post_id = self::factory()->post->create(
			[
				'post_type' => Plugin::POST_TYPE,
				'post_name' => 'traversal-skill',
				'post_content' => implode(
					'',
					[
						'<!-- wp:agent-pilot/agent-skill-reference {"fileName":"../guide","format":"md"} --><div class="wp-block-agent-pilot-agent-skill-reference"><!-- wp:paragraph --><p>Guide.</p><!-- /wp:paragraph --></div><!-- /wp:agent-pilot/agent-skill-reference -->',
						$this->serialize_script_block( '../../evil.sh', "echo evil\n" ),
						$this->serialize_script_block( 'nested/run.sh', "echo nested\n" ),
						'<!-- wp:agent-pilot/agent-skill-asset {"attachmentId":123,"fileName":"../SKILL.md"} --><div class="wp-block-agent-pilot-agent-skill-asset"></div><!-- /wp:agent-pilot/agent-skill-asset -->',
					]
				),
			]
		);
		$skill = Skill::from_post_id( $post_id );

		$this->assertSame(
			[ 'references/guide.md' ],
			array_map( fn ( $reference ): ?string => $reference->get_filename(), array_values( $skill->get_references() ) ),
			'Path traversal sequences in reference filenames should be normalized away.'
		);
		$this->assertSame(
			[ 'scripts/evil.sh', 'scripts/nestedrun.sh' ],
			array_map( fn ( $script ): ?string => $script->get_filename(), array_values( $skill->get_scripts() ) ),
			'Directory separators and path traversal sequences in script filenames should be normalized away.'
		);
		$this->assertSame(
			[ 'assets/SKILL.md' ],
			array_map( fn ( $asset ): ?string => $asset->get_filename(), array_values( $skill->get_assets() ) ),
			'Asset filenames should never escape the assets directory of the generated archive.'
		);
	}

	public function test_resources_with_filenames_that_normalize_to_nothing_are_not_publishable() {
		$post_id = self::factory()->post->create(
			[
				'post_type' => Plugin::POST_TYPE,
				'post_name' => 'unresolvable-skill',
				'post_content' => implode(
					'',
					[
						$this->serialize_script_block( '../', "echo\n" ),
						'<!-- wp:agent-pilot/agent-skill-asset {"attachmentId":123,"fileName":"../"} --><div class="wp-block-agent-pilot-agent-skill-asset"></div><!-- /wp:agent-pilot/agent-skill-asset -->',
					]
				),
			]
		);
		$skill = Skill::from_post_id( $post_id );

		$this->assertNull( array_values( $skill->get_scripts() )[0]->get_filename(), 'A script filename that normalizes to nothing should not resolve to an archive path.' );
		$this->assertFalse( array_values( $skill->get_scripts() )[0]->is_valid(), 'A script without a usable filename should not be publishable.' );
		$this->assertFalse( array_values( $skill->get_assets() )[0]->is_valid(), 'An asset whose filename normalizes to nothing should not be publishable even with an attachment selected.' );
	}

	public function test_resolves_resource_blocks_into_their_packaged_file_paths() {
		$skill = Skill::from_post_id( $this->create_skill_with_resources() );

		$this->assertSame(
			[ 'references/guide.md' ],
			array_map( fn ( $reference ): ?string => $reference->get_filename(), array_values( $skill->get_references() ) ),
			'Reference blocks should be packaged under the references directory using their filename and format attributes.'
		);
		$this->assertSame(
			[ 'scripts/hello.sh' ],
			array_map( fn ( $script ): ?string => $script->get_filename(), array_values( $skill->get_scripts() ) ),
			'Script blocks should be packaged under the scripts directory using their filename verbatim.'
		);
		$this->assertSame(
			[ 'assets/diagram.png' ],
			array_map( fn ( $asset ): ?string => $asset->get_filename(), array_values( $skill->get_assets() ) ),
			'Asset blocks should be packaged under the assets directory using their filename verbatim.'
		);
	}

	public function test_lists_resources_in_skill_markdown_without_inlining_their_contents() {
		$markdown = Skill::from_post_id( $this->create_skill_with_resources() )->get_as_markdown();

		$this->assertStringContainsString( 'Run the helper.', $markdown, 'Regular instruction blocks should remain in SKILL.md.' );
		$this->assertStringNotContainsString( 'echo hello', $markdown, 'Script contents should not be inlined into SKILL.md.' );
		$this->assertStringNotContainsString( 'Detailed guide', $markdown, 'Reference contents should not be inlined into SKILL.md.' );
		$this->assertStringContainsString( "## References\n\n- references/guide.md", $markdown, 'References should be listed in their own SKILL.md section.' );
		$this->assertStringContainsString( "## Assets\n\n- assets/diagram.png", $markdown, 'Assets should be listed in their own SKILL.md section.' );
		$this->assertStringContainsString( "## Scripts\n\n- scripts/hello.sh", $markdown, 'Scripts should be listed in their own SKILL.md section.' );
	}

	public function test_script_content_comes_back_as_the_exact_authored_bytes() {
		$content = "#!/bin/sh\nif [ \"\$1\" -lt 3 ] && [ \"\$1\" -gt 1 ]; then\n\techo '<ok> & done'\nfi\n";
		$post_id = self::factory()->post->create(
			[
				'post_type' => Plugin::POST_TYPE,
				'post_name' => 'entity-script-skill',
				'post_content' => $this->serialize_script_block( 'check.sh', $content ),
			]
		);
		$script = array_values( Skill::from_post_id( $post_id )->get_scripts() )[0];

		$this->assertNull( $script->get_attribute( 'content' ), 'Script content is sourced from the markup, so it should never reach PHP as a block attribute.' );
		$this->assertSame( $content, $script->get_content(), 'Script content should be read out of the saved <pre><code> markup with its HTML entities decoded.' );
	}

	public function test_script_content_is_absent_until_the_block_is_written() {
		$post_id = self::factory()->post->create(
			[
				'post_type' => Plugin::POST_TYPE,
				'post_name' => 'empty-script-skill',
				'post_content' => '<!-- wp:agent-pilot/agent-skill-script {"fileName":"empty.sh"} --><div class="wp-block-agent-pilot-agent-skill-script"></div><!-- /wp:agent-pilot/agent-skill-script -->',
			]
		);
		$script = array_values( Skill::from_post_id( $post_id )->get_scripts() )[0];

		$this->assertNull( $script->get_content(), 'A Script block saved without a code element should not resolve to any content.' );
	}

	public function test_reference_filenames_move_a_trailing_extension_into_the_format() {
		$post_id = self::factory()->post->create(
			[
				'post_type' => Plugin::POST_TYPE,
				'post_name' => 'html-reference-skill',
				'post_content' => '<!-- wp:agent-pilot/agent-skill-reference {"fileName":"guide.html","format":"html"} --><div class="wp-block-agent-pilot-agent-skill-reference"></div><!-- /wp:agent-pilot/agent-skill-reference -->',
			]
		);
		$references = array_values( Skill::from_post_id( $post_id )->get_references() );

		$this->assertSame( 'references/guide.html', $references[0]->get_filename(), 'A filename that already carries its extension should not gain a second one.' );
		$this->assertSame( 'html', $references[0]->get_format(), 'The stored format attribute should decide the published reference format.' );
	}

	public function test_publishes_custom_reference_content_in_the_selected_format() {
		$this->assertSame(
			"## Markdown Guide\n\nUse **blocks**.",
			$this->create_reference_skill( 'md' )->get_files()['references/guide.md'],
			'A Markdown reference should convert the blocks authored inside it to Markdown.'
		);

		$html = $this->create_reference_skill( 'html' )->get_files()['references/guide.html'];

		$this->assertStringContainsString( '<h2 class="wp-block-heading">Markdown Guide</h2>', $html, 'An HTML reference should keep its rendered markup.' );
		$this->assertStringContainsString( 'Use <strong>blocks</strong>.', $html, 'An HTML reference should keep its inline markup.' );
		$this->assertStringNotContainsString( 'wp-block-agent-pilot-agent-skill-reference', $html, 'The Reference block wrapper should not end up in the published file.' );
	}

	public function test_a_linked_post_takes_over_from_the_custom_reference_content() {
		$linked_post_id = self::factory()->post->create(
			[
				'post_content' => '<!-- wp:paragraph --><p>Linked guidance.</p><!-- /wp:paragraph -->',
			]
		);
		$post_id = self::factory()->post->create(
			[
				'post_type' => Plugin::POST_TYPE,
				'post_name' => 'linked-reference-skill',
				'post_content' => sprintf(
					'<!-- wp:agent-pilot/agent-skill-reference {"fileName":"guide","format":"md","postId":%d} --><div class="wp-block-agent-pilot-agent-skill-reference"><!-- wp:paragraph --><p>Ignored custom content.</p><!-- /wp:paragraph --></div><!-- /wp:agent-pilot/agent-skill-reference -->',
					$linked_post_id
				),
			]
		);

		$this->assertSame(
			'Linked guidance.',
			Skill::from_post_id( $post_id )->get_files()['references/guide.md'],
			'A selected post should be the source of the reference, in place of any custom content.'
		);
	}

	public function test_reference_without_content_publishes_nothing() {
		$post_id = self::factory()->post->create(
			[
				'post_type' => Plugin::POST_TYPE,
				'post_name' => 'empty-reference-skill',
				'post_content' => '<!-- wp:agent-pilot/agent-skill-reference {"fileName":"guide","format":"md"} --><div class="wp-block-agent-pilot-agent-skill-reference"></div><!-- /wp:agent-pilot/agent-skill-reference -->',
			]
		);
		$reference = array_values( Skill::from_post_id( $post_id )->get_references() )[0];

		$this->assertNull( $reference->get_content(), 'A Reference placeholder should not publish an empty file.' );
	}

	public function test_asset_content_is_the_exact_attachment_bytes() {
		$upload = wp_upload_bits( 'agent-pilot-example.txt', null, "Asset bytes\n" );
		$attachment_id = self::factory()->attachment->create_object(
			$upload['file'],
			0,
			[
				'post_mime_type' => 'text/plain',
			]
		);
		$post_id = self::factory()->post->create(
			[
				'post_type' => Plugin::POST_TYPE,
				'post_name' => 'asset-skill',
				'post_content' => sprintf( '<!-- wp:agent-pilot/agent-skill-asset {"attachmentId":%d,"fileName":"notes.txt"} --><div class="wp-block-agent-pilot-agent-skill-asset"></div><!-- /wp:agent-pilot/agent-skill-asset -->', $attachment_id ),
			]
		);
		$asset = array_values( Skill::from_post_id( $post_id )->get_assets() )[0];

		$this->assertSame( "Asset bytes\n", $asset->get_content(), 'Assets should publish the attachment bytes untouched, whatever their extension implies.' );
	}

	public function test_skips_resources_that_are_not_fully_configured() {
		$post_id = self::factory()->post->create(
			[
				'post_type' => Plugin::POST_TYPE,
				'post_name' => 'placeholder-skill',
				'post_content' => implode(
					'',
					[
						'<!-- wp:agent-pilot/agent-skill --><div class="wp-block-agent-pilot-agent-skill">',
						'<!-- wp:paragraph --><p>Instructions.</p><!-- /wp:paragraph -->',
						'<!-- wp:agent-pilot/agent-skill-reference {"fileName":"","format":"md"} --><div class="wp-block-agent-pilot-agent-skill-reference"></div><!-- /wp:agent-pilot/agent-skill-reference -->',
						'<!-- wp:agent-pilot/agent-skill-script --><div class="wp-block-agent-pilot-agent-skill-script"></div><!-- /wp:agent-pilot/agent-skill-script -->',
						'<!-- wp:agent-pilot/agent-skill-asset {"fileName":"diagram.png"} --><div class="wp-block-agent-pilot-agent-skill-asset"></div><!-- /wp:agent-pilot/agent-skill-asset -->',
						'</div><!-- /wp:agent-pilot/agent-skill -->',
					]
				),
			]
		);
		$skill = Skill::from_post_id( $post_id );
		$markdown = $skill->get_as_markdown();

		$this->assertFalse( array_values( $skill->get_references() )[0]->is_valid(), 'A Reference placeholder without a filename should not be publishable.' );
		$this->assertFalse( array_values( $skill->get_scripts() )[0]->is_valid(), 'A Script placeholder without a filename should not be publishable.' );
		$this->assertFalse( array_values( $skill->get_assets() )[0]->is_valid(), 'An Asset placeholder without an attachment should not be publishable.' );
		$this->assertStringNotContainsString( '## References', $markdown, 'Unconfigured placeholders should not open a References section.' );
		$this->assertStringNotContainsString( '## Assets', $markdown, 'Unconfigured placeholders should not open an Assets section.' );
		$this->assertStringNotContainsString( '## Scripts', $markdown, 'Unconfigured placeholders should not open a Scripts section.' );
	}

	public function test_reports_whether_a_skill_needs_to_be_published_as_an_archive() {
		$plain_post_id = self::factory()->post->create(
			[
				'post_type' => Plugin::POST_TYPE,
				'post_name' => 'plain-skill',
				'post_content' => '<!-- wp:paragraph --><p>Instructions only.</p><!-- /wp:paragraph -->',
			]
		);

		$this->assertFalse( Skill::from_post_id( $plain_post_id )->is_archive(), 'A skill without resource blocks is a single SKILL.md file.' );
		$this->assertTrue( Skill::from_post_id( $this->create_skill_with_resources() )->is_archive(), 'A skill carrying resource blocks needs to be published as an archive.' );
	}

	private function create_skill_with_resources(): int {
		$script = $this->serialize_script_block( 'hello.sh', "#!/bin/sh\necho hello\n" );

		return self::factory()->post->create(
			[
				'post_type' => Plugin::POST_TYPE,
				'post_name' => 'scripted-skill',
				'post_title' => 'Scripted Skill',
				'post_content' => implode(
					'',
					[
						'<!-- wp:agent-pilot/agent-skill --><div class="wp-block-agent-pilot-agent-skill">',
						'<!-- wp:paragraph --><p>Run the helper.</p><!-- /wp:paragraph -->',
						'<!-- wp:agent-pilot/agent-skill-reference {"fileName":"guide","format":"md"} --><div class="wp-block-agent-pilot-agent-skill-reference"><!-- wp:paragraph --><p>Detailed guide.</p><!-- /wp:paragraph --></div><!-- /wp:agent-pilot/agent-skill-reference -->',
						'<!-- wp:agent-pilot/agent-skill-asset {"attachmentId":123,"fileName":"diagram.png"} --><div class="wp-block-agent-pilot-agent-skill-asset"></div><!-- /wp:agent-pilot/agent-skill-asset -->',
						$script,
						'</div><!-- /wp:agent-pilot/agent-skill -->',
					]
				),
			]
		);
	}

	private function create_reference_skill( string $format ): Skill {
		$post_id = self::factory()->post->create(
			[
				'post_type' => Plugin::POST_TYPE,
				'post_name' => sprintf( 'reference-%s-skill', $format ),
				'post_content' => sprintf(
					'<!-- wp:agent-pilot/agent-skill-reference {"fileName":"guide","format":"%s"} --><div class="wp-block-agent-pilot-agent-skill-reference"><!-- wp:heading --><h2 class="wp-block-heading">Markdown Guide</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Use <strong>blocks</strong>.</p><!-- /wp:paragraph --></div><!-- /wp:agent-pilot/agent-skill-reference -->',
					$format
				),
			]
		);

		return Skill::from_post_id( $post_id );
	}

	/**
	 * Mirror how the editor saves a Script block: the content lives as the text of
	 * the <pre><code> markup, never as an attribute in the block delimiter.
	 */
	private function serialize_script_block( string $filename, string $content ): string {
		$markup = sprintf(
			'<pre class="wp-block-agent-pilot-agent-skill-script"><code>%s</code></pre>',
			esc_html( $content )
		);

		return serialize_block(
			[
				'blockName' => Skill::SCRIPT_BLOCK_NAME,
				'attrs' => [
					'fileName' => $filename,
				],
				'innerBlocks' => [],
				'innerHTML' => $markup,
				'innerContent' => [ $markup ],
			]
		);
	}
}
