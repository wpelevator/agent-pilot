<?php

namespace WPElevator\Agent_Pilot_Tests;

use WPElevator\Agent_Pilot\Agent_Plugin;
use WPElevator\Agent_Pilot\Plugin;
use WPElevator\Agent_Pilot\Skills;

class Agent_Plugin_Test extends \WP_UnitTestCase {

	public function test_registered_block_schema_filters_invalid_attributes() {
		$plugin = $this->create_plugin(
			'schema-attributes',
			[
				'<!-- wp:agent-pilot/agent-plugin-skill {"skillId":"not-a-number"} /-->',
				'<!-- wp:agent-pilot/agent-plugin-mcp-server {"name":"example","definition":123} /-->',
			],
			'{"license":123}'
		);

		$this->assertArrayNotHasKey( 'license', $plugin->get_manifest(), 'Invalid manifest attributes should be removed by the registered block schema.' );
		$this->assertSame( [], $plugin->get_skills(), 'Invalid skill IDs should be removed by the registered block schema.' );
		$this->assertSame( [], $plugin->get_servers()[0]->to_array(), 'Invalid MCP definitions should fall back to the block attribute default.' );
	}

	public function test_unselected_skill_block_does_not_invalidate_the_plugin() {
		$plugin = $this->create_plugin(
			'unselected-skill',
			[
				'<!-- wp:agent-pilot/agent-plugin-skill /-->',
				'<!-- wp:agent-pilot/agent-plugin-mcp-server {"name":"example","definition":"{\"type\":\"streamable-http\",\"url\":\"https://example.com/mcp\"}"} /-->',
			]
		);

		$this->assertSame( [], $plugin->get_errors(), 'A skill block with nothing selected yet should be skipped rather than fail validation.' );
		$this->assertTrue( $plugin->is_valid(), 'An MCP server alone should package even while an empty skill block is still in the editor.' );
		$this->assertSame( [ 'plugin.json', 'mcp.json' ], array_keys( $plugin->get_files() ), 'An unselected skill block should contribute no files.' );
	}

	public function test_unselected_skill_block_alone_still_requires_a_component() {
		$plugin = $this->create_plugin( 'empty-plugin', [ '<!-- wp:agent-pilot/agent-plugin-skill /-->' ] );

		$this->assertSame( [ 'A plugin requires a skill or MCP server.' ], $plugin->get_errors(), 'An unselected skill block should not count as a component.' );
	}

	public function test_skill_block_referencing_a_missing_post_still_fails() {
		$post_id = self::factory()->post->create( [ 'post_type' => 'post' ] );
		$plugin = $this->create_plugin(
			'missing-skill',
			[ sprintf( '<!-- wp:agent-pilot/agent-plugin-skill {"skillId":%d} /-->', $post_id ) ]
		);

		$this->assertContains( 'Each selected skill must reference an existing Agent Skill.', $plugin->get_errors(), 'A selected skill that is not an Agent Skill post should still fail validation.' );
	}

	private function create_plugin( string $name, array $children, string $attributes = '' ): Agent_Plugin {
		$content = implode(
			'',
			array_merge(
				[
					sprintf( '<!-- wp:agent-pilot/agent-plugin%s -->', '' !== $attributes ? ' ' . $attributes : '' ),
					'<div class="wp-block-agent-pilot-agent-plugin">',
				],
				$children,
				[ '</div><!-- /wp:agent-pilot/agent-plugin -->' ]
			)
		);

		$post_id = self::factory()->post->create(
			[
				'post_type' => Plugin::POST_TYPE_AGENT_PLUGIN,
				'post_name' => $name,
				// wp_insert_post() unslashes, so escaped quotes inside block attributes need slashing to survive the round trip.
				'post_content' => wp_slash( $content ),
			]
		);

		return new Agent_Plugin( get_post( $post_id ), new Skills( Plugin::POST_TYPE_AGENT_SKILL ) );
	}
}
