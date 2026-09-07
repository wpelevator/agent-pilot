<?php

namespace WPElevator\Agent_Pilot_Tests;

use WPElevator\Agent_Pilot\MCP\Authentication;
use WPElevator\Agent_Pilot\Plugin;

require_once __DIR__ . '/class-mcp-test-case.php';

class Skill_Abilities_Test extends MCP_Test_Case {

	public function set_up() {
		parent::set_up();

		Plugin::action_register_post_type();
	}

	public function test_read_abilities_are_published_as_read_scope_mcp_tools() {
		$tools = wp_list_pluck( $this->tools->get_tools( $this->get_token_identity( [ Authentication::SCOPE_READ ] ) ), 'name' );

		$this->assertInstanceOf( \WP_Ability::class, wp_get_ability( Plugin::ABILITY_LIST_SKILLS ), 'The skills component should register its listing ability.' );
		$this->assertInstanceOf( \WP_Ability::class, wp_get_ability( Plugin::ABILITY_GET_SKILL ), 'The skills component should register its read ability.' );
		$this->assertContains( 'agent-pilot.list-agent-skills', $tools, 'Reading skills should be available to a client holding only the read scope.' );
		$this->assertContains( 'agent-pilot.get-agent-skill', $tools, 'Reading one skill should be available to a client holding only the read scope.' );
	}

	public function test_listing_shows_published_skills_to_anonymous_callers_and_hides_the_rest() {
		$this->create_skill( 'zebra-skill', [ 'post_status' => 'publish' ] );
		$this->create_skill( 'alpha-skill', [ 'post_status' => 'publish' ] );
		$this->create_skill( 'pending-skill', [ 'post_status' => 'pending' ] );
		$this->create_skill( 'private-skill', [ 'post_status' => 'private' ] );

		wp_set_current_user( 0 );

		$result = wp_get_ability( Plugin::ABILITY_LIST_SKILLS )->execute();

		$this->assertSame(
			[ 'alpha-skill', 'zebra-skill' ],
			wp_list_pluck( $result['skills'], 'name' ),
			'A published skill is already public, so listing it should need no capability, while everything else stays hidden.'
		);
	}

	public function test_listing_shows_unpublished_skills_to_a_caller_who_can_read_them() {
		$this->create_skill( 'public-skill', [ 'post_status' => 'publish' ] );
		$this->create_skill( 'private-skill', [ 'post_status' => 'private' ] );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$result = wp_get_ability( Plugin::ABILITY_LIST_SKILLS )->execute();

		$this->assertSame(
			[ 'private-skill', 'public-skill' ],
			wp_list_pluck( $result['skills'], 'name' ),
			'Unpublished skills should follow the capabilities WordPress maps for the post type rather than being hidden from everyone.'
		);
	}

	public function test_listing_reports_the_packaged_files_and_published_urls() {
		$post_id = $this->create_skill(
			'documented-skill',
			[
				'post_status' => 'publish',
				'post_title' => 'Documented Skill',
				'post_excerpt' => 'Do the documented thing.',
				'post_content' => $this->get_skill_content(),
			]
		);
		wp_set_current_user( 0 );

		$listed = wp_get_ability( Plugin::ABILITY_LIST_SKILLS )->execute()['skills'][0];

		$this->assertSame( $post_id, $listed['id'], 'A listing should carry the post ID, so that a caller can reach the same skill over the REST API.' );
		$this->assertSame( 'Do the documented thing.', $listed['description'], 'The description should come from the post excerpt, as the generated front matter does.' );
		$this->assertTrue( $listed['is_public'], 'A published skill should be reported as publicly available.' );
		$this->assertSame(
			[ 'SKILL.md', 'scripts/build.sh', 'references/guide.md' ],
			$listed['files'],
			'A listing should name every packaged file, so that a caller knows what it can ask for.'
		);
		$this->assertSame( get_permalink( $post_id ), $listed['permalink'], 'A published skill should be listed under its own permalink.' );
		$this->assertStringEndsWith( '/skill.zip', $listed['package_url'], 'A skill can carry references, scripts and assets, so the URL it is published under should be the archive that holds them rather than the Markdown alone.' );
		$this->assertArrayNotHasKey( 'skill_url', $listed, 'Handing out the SKILL.md URL as well would offer an incomplete copy of a skill that has resources.' );
	}

	public function test_reading_a_skill_returns_its_generated_markdown_by_default() {
		$this->create_skill(
			'documented-skill',
			[
				'post_status' => 'publish',
				'post_title' => 'Documented Skill',
				'post_excerpt' => 'Do the documented thing.',
				'post_content' => $this->get_skill_content(),
			]
		);

		wp_set_current_user( 0 );

		$result = wp_get_ability( Plugin::ABILITY_GET_SKILL )->execute( [ 'name' => 'documented-skill' ] );

		$this->assertSame( 'SKILL.md', $result['file'], 'Reading a skill without naming a file should answer with its SKILL.md.' );
		$this->assertStringContainsString( 'name: documented-skill', $result['content'], 'The answer should be the generated file rather than the block markup it was authored as.' );
		$this->assertStringContainsString( '# Documented Skill', $result['content'], 'The generated Markdown should carry the skill instructions.' );
	}

	public function test_reading_a_named_file_returns_that_resource() {
		$this->create_skill(
			'documented-skill',
			[
				'post_status' => 'publish',
				'post_content' => $this->get_skill_content(),
			]
		);

		wp_set_current_user( 0 );

		$reference = wp_get_ability( Plugin::ABILITY_GET_SKILL )->execute(
			[
				'name' => 'documented-skill',
				'file' => 'references/guide.md',
			]
		);
		$script = wp_get_ability( Plugin::ABILITY_GET_SKILL )->execute(
			[
				'name' => 'documented-skill',
				'file' => 'scripts/build.sh',
			]
		);

		$this->assertStringContainsString( 'Read the guide.', $reference['content'], 'A reference should be answered as the Markdown it is packaged as.' );
		$this->assertStringContainsString( 'composer test', $script['content'], 'A script should be answered as the text it is packaged as.' );
	}

	public function test_reading_an_asset_answers_with_its_download_url() {
		$upload = wp_upload_bits( 'agent-pilot-example.txt', null, "Asset bytes\n" );
		$attachment_id = self::factory()->attachment->create_object(
			$upload['file'],
			0,
			[ 'post_mime_type' => 'text/plain' ]
		);
		$this->create_skill(
			'asset-skill',
			[
				'post_status' => 'publish',
				'post_content' => sprintf( '<!-- wp:agent-pilot/agent-skill-asset {"attachmentId":%d,"fileName":"notes.txt"} --><div class="wp-block-agent-pilot-agent-skill-asset"></div><!-- /wp:agent-pilot/agent-skill-asset -->', $attachment_id ),
			]
		);

		wp_set_current_user( 0 );

		$result = wp_get_ability( Plugin::ABILITY_GET_SKILL )->execute(
			[
				'name' => 'asset-skill',
				'file' => 'assets/notes.txt',
			]
		);

		$this->assertWPError( $result, 'An uploaded file should not be read into a tool result.' );
		$this->assertSame( 'agent_pilot_skill_file_is_asset', $result->get_error_code(), 'An asset should be refused as an upload rather than as a missing file.' );
		$this->assertStringContainsString( wp_get_attachment_url( $attachment_id ), $result->get_error_message(), 'The refusal should name the URL the asset can be downloaded from.' );
	}

	public function test_reading_an_unknown_file_names_the_skill_that_does_not_have_it() {
		$this->create_skill( 'documented-skill', [ 'post_status' => 'publish' ] );

		wp_set_current_user( 0 );

		$result = wp_get_ability( Plugin::ABILITY_GET_SKILL )->execute(
			[
				'name' => 'documented-skill',
				'file' => 'references/missing.md',
			]
		);

		$this->assertWPError( $result, 'A file the skill does not package should be an error a model can correct.' );
		$this->assertSame( 'agent_pilot_skill_file_not_found', $result->get_error_code(), 'An unknown file should be reported as missing.' );
	}

	public function test_an_unreadable_skill_is_refused_the_same_way_as_a_missing_one() {
		$this->create_skill( 'hidden-skill', [ 'post_status' => 'draft' ] );

		wp_set_current_user( 0 );

		$hidden = wp_get_ability( Plugin::ABILITY_GET_SKILL )->execute( [ 'name' => 'hidden-skill' ] );
		$missing = wp_get_ability( Plugin::ABILITY_GET_SKILL )->execute( [ 'name' => 'no-such-skill' ] );

		$this->assertWPError( $hidden, 'A draft should not be readable by an anonymous caller.' );
		$this->assertSame( 'agent_pilot_skill_not_found', $hidden->get_error_code(), 'A skill the caller may not read should be indistinguishable from one that does not exist.' );
		$this->assertSame( $missing->get_error_code(), $hidden->get_error_code(), 'Comparing the two refusals should not reveal that an unpublished name exists.' );
	}

	public function test_a_draft_can_be_read_back_under_the_name_the_listing_gave_it() {
		$post_id = $this->create_skill( '', [ 'post_status' => 'draft' ] );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$listed = wp_get_ability( Plugin::ABILITY_LIST_SKILLS )->execute()['skills'][0];
		$result = wp_get_ability( Plugin::ABILITY_GET_SKILL )->execute( [ 'name' => $listed['name'] ] );

		$this->assertSame( sprintf( 'agent-skill-%d-draft', $post_id ), $listed['name'], 'A skill with no slug yet should be listed under a name derived from its post ID.' );
		$this->assertSame( $post_id, $result['id'], 'The generated draft name should resolve back to the same skill.' );
	}

	private function get_skill_content(): string {
		return implode(
			'',
			[
				'<!-- wp:agent-pilot/agent-skill --><div class="wp-block-agent-pilot-agent-skill">',
				'<!-- wp:paragraph --><p>Do the thing.</p><!-- /wp:paragraph -->',
				'</div><!-- /wp:agent-pilot/agent-skill -->',
				'<!-- wp:agent-pilot/agent-skill-script {"fileName":"build.sh"} --><pre class="wp-block-agent-pilot-agent-skill-script"><code>composer test</code></pre><!-- /wp:agent-pilot/agent-skill-script -->',
				'<!-- wp:agent-pilot/agent-skill-reference {"fileName":"guide","format":"md"} --><div class="wp-block-agent-pilot-agent-skill-reference"><!-- wp:paragraph --><p>Read the guide.</p><!-- /wp:paragraph --></div><!-- /wp:agent-pilot/agent-skill-reference -->',
			]
		);
	}

	private function create_skill( string $name, array $args = [] ): int {
		return self::factory()->post->create(
			array_merge(
				[
					'post_type' => Plugin::POST_TYPE_AGENT_SKILL,
					'post_name' => $name,
					'post_content' => '<!-- wp:agent-pilot/agent-skill --><div class="wp-block-agent-pilot-agent-skill"><!-- wp:paragraph --><p>Do the thing.</p><!-- /wp:paragraph --></div><!-- /wp:agent-pilot/agent-skill -->',
				],
				$args
			)
		);
	}
}
