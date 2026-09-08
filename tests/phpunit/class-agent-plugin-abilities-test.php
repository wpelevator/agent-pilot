<?php

namespace WPElevator\Agent_Pilot_Tests;

use WPElevator\Agent_Pilot\MCP\Authentication;
use WPElevator\Agent_Pilot\Plugin;

require_once __DIR__ . '/class-mcp-test-case.php';

class Agent_Plugin_Abilities_Test extends MCP_Test_Case {

	public function set_up() {
		parent::set_up();

		Plugin::action_register_post_type();
	}

	public function test_read_abilities_are_published_as_read_scope_mcp_tools() {
		$tools = wp_list_pluck( $this->tools->get_tools( $this->get_token_identity( [ Authentication::SCOPE_READ ] ) ), 'name' );

		$this->assertInstanceOf( \WP_Ability::class, wp_get_ability( Plugin::ABILITY_LIST_PLUGINS ), 'The plugins component should register its listing ability.' );
		$this->assertInstanceOf( \WP_Ability::class, wp_get_ability( Plugin::ABILITY_GET_PLUGIN ), 'The plugins component should register its read ability.' );
		$this->assertContains( 'agent-pilot-list-agent-plugins', $tools, 'Reading Agent Plugins should be available to a client holding only the read scope.' );
		$this->assertContains( 'agent-pilot-get-agent-plugin', $tools, 'Reading one Agent Plugin should be available to a client holding only the read scope.' );
	}

	public function test_listing_shows_published_plugins_to_anonymous_callers_and_hides_the_rest() {
		$this->create_plugin( 'published-plugin', 'publish' );
		$this->create_plugin( 'draft-plugin', 'draft' );

		wp_set_current_user( 0 );

		$result = wp_get_ability( Plugin::ABILITY_LIST_PLUGINS )->execute();

		$this->assertSame(
			[ 'published-plugin' ],
			wp_list_pluck( $result['plugins'], 'name' ),
			'A published Agent Plugin is already public, while a draft should stay hidden from a caller who cannot read it.'
		);
	}

	public function test_listing_reports_the_bundled_skills_servers_and_validation_errors() {
		$skill_id = self::factory()->post->create(
			[
				'post_type' => Plugin::POST_TYPE_AGENT_SKILL,
				'post_name' => 'bundled-skill',
				'post_status' => 'draft',
				'post_content' => '<!-- wp:agent-pilot/agent-skill --><div class="wp-block-agent-pilot-agent-skill"><!-- wp:paragraph --><p>Do the thing.</p><!-- /wp:paragraph --></div><!-- /wp:agent-pilot/agent-skill -->',
			]
		);
		$this->create_plugin(
			'documented-plugin',
			'publish',
			[
				sprintf( '<!-- wp:agent-pilot/agent-plugin-skill {"skillId":%d} /-->', $skill_id ),
				'<!-- wp:agent-pilot/agent-plugin-mcp-server {"name":"example","definition":"{\"type\":\"streamable-http\",\"url\":\"https://example.com/mcp\"}"} /-->',
			]
		);

		wp_set_current_user( 0 );

		$listed = wp_get_ability( Plugin::ABILITY_LIST_PLUGINS )->execute()['plugins'][0];

		$this->assertSame( [ 'bundled-skill' ], $listed['skills'], 'A listing should name the skills the Agent Plugin bundles.' );
		$this->assertSame( [ 'example' ], $listed['mcp_servers'], 'A listing should name the MCP servers the Agent Plugin configures.' );
		$this->assertSame( [ 'plugin.json', 'mcp.json', 'skills/bundled-skill/SKILL.md' ], $listed['files'], 'A listing should name every file the package contains.' );
		$this->assertSame(
			[ 'Selected skill bundled-skill is not published.' ],
			$listed['errors'],
			'A published Agent Plugin that cannot be served should say why, since its routes answer with nothing at all.'
		);
	}

	public function test_reading_a_plugin_returns_its_manifest_by_default() {
		$this->create_plugin( 'documented-plugin', 'publish' );

		wp_set_current_user( 0 );

		$result = wp_get_ability( Plugin::ABILITY_GET_PLUGIN )->execute( [ 'name' => 'documented-plugin' ] );

		$this->assertSame( 'plugin.json', $result['file'], 'Reading an Agent Plugin without naming a file should answer with its manifest.' );
		$this->assertStringContainsString( '"name": "documented-plugin"', $result['content'], 'The answer should be the generated manifest rather than the block markup it was authored as.' );
	}

	public function test_reading_a_named_file_returns_that_part_of_the_package() {
		$this->create_plugin( 'documented-plugin', 'publish' );

		wp_set_current_user( 0 );

		$result = wp_get_ability( Plugin::ABILITY_GET_PLUGIN )->execute(
			[
				'name' => 'documented-plugin',
				'file' => 'mcp.json',
			]
		);

		$this->assertStringContainsString( 'https://example.com/mcp', $result['content'], 'The MCP server definition should be answered as the file the package publishes.' );
	}

	public function test_a_bundled_asset_is_answered_with_its_own_download_url() {
		$upload = wp_upload_bits( 'agent-pilot-diagram.txt', null, "Asset bytes\n" );
		$attachment_id = self::factory()->attachment->create_object(
			$upload['file'],
			0,
			[ 'post_mime_type' => 'text/plain' ]
		);
		$skill_id = self::factory()->post->create(
			[
				'post_type' => Plugin::POST_TYPE_AGENT_SKILL,
				'post_name' => 'bundled-skill',
				'post_status' => 'publish',
				'post_content' => sprintf( '<!-- wp:agent-pilot/agent-skill-asset {"attachmentId":%d,"fileName":"notes.txt"} --><div class="wp-block-agent-pilot-agent-skill-asset"></div><!-- /wp:agent-pilot/agent-skill-asset -->', $attachment_id ),
			]
		);
		$this->create_plugin(
			'documented-plugin',
			'publish',
			[ sprintf( '<!-- wp:agent-pilot/agent-plugin-skill {"skillId":%d} /-->', $skill_id ) ]
		);

		wp_set_current_user( 0 );

		$result = wp_get_ability( Plugin::ABILITY_GET_PLUGIN )->execute(
			[
				'name' => 'documented-plugin',
				'file' => 'skills/bundled-skill/assets/notes.txt',
			]
		);

		$this->assertWPError( $result, 'A bundled asset is an uploaded file, so it should not be read into a tool result.' );
		$this->assertStringContainsString(
			wp_get_attachment_url( $attachment_id ),
			$result->get_error_message(),
			'A package carries which of its files are assets and where they came from, so it can name the URL rather than send the caller to look for the skill.'
		);
	}

	public function test_an_unreadable_plugin_is_refused_the_same_way_as_a_missing_one() {
		$this->create_plugin( 'hidden-plugin', 'draft' );

		wp_set_current_user( 0 );

		$hidden = wp_get_ability( Plugin::ABILITY_GET_PLUGIN )->execute( [ 'name' => 'hidden-plugin' ] );
		$missing = wp_get_ability( Plugin::ABILITY_GET_PLUGIN )->execute( [ 'name' => 'no-such-plugin' ] );

		$this->assertWPError( $hidden, 'A draft Agent Plugin should not be readable by an anonymous caller.' );
		$this->assertSame( 'agent_pilot_plugin_not_found', $hidden->get_error_code(), 'An Agent Plugin the caller may not read should be indistinguishable from one that does not exist.' );
		$this->assertSame( $missing->get_error_code(), $hidden->get_error_code(), 'Comparing the two refusals should not reveal that an unpublished name exists.' );
	}

	public function test_reading_an_unknown_file_names_the_plugin_that_does_not_have_it() {
		$this->create_plugin( 'documented-plugin', 'publish' );

		wp_set_current_user( 0 );

		$result = wp_get_ability( Plugin::ABILITY_GET_PLUGIN )->execute(
			[
				'name' => 'documented-plugin',
				'file' => 'skills/missing/SKILL.md',
			]
		);

		$this->assertWPError( $result, 'A file the package does not contain should be an error a model can correct.' );
		$this->assertSame( 'agent_pilot_plugin_file_not_found', $result->get_error_code(), 'An unknown file should be reported as missing.' );
	}

	private function create_plugin( string $name, string $status, ?array $children = null ): int {
		$content = implode(
			'',
			array_merge(
				[
					'<!-- wp:agent-pilot/agent-plugin --><div class="wp-block-agent-pilot-agent-plugin">',
				],
				$children ?? [ '<!-- wp:agent-pilot/agent-plugin-mcp-server {"name":"example","definition":"{\"type\":\"streamable-http\",\"url\":\"https://example.com/mcp\"}"} /-->' ],
				[ '</div><!-- /wp:agent-pilot/agent-plugin -->' ]
			)
		);

		return self::factory()->post->create(
			[
				'post_type' => Plugin::POST_TYPE_AGENT_PLUGIN,
				'post_name' => $name,
				'post_status' => $status,
				// wp_insert_post() unslashes, so escaped quotes inside block attributes need slashing to survive the round trip.
				'post_content' => wp_slash( $content ),
			]
		);
	}
}
