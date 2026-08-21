<?php

namespace WPElevator\Agent_Pilot_Tests;

use WPElevator\Agent_Pilot\Agent_Plugin;
use WPElevator\Agent_Pilot\Plugin;
use WPElevator\Agent_Pilot\Skills;

class Agent_Plugin_Test extends \WP_UnitTestCase {

	public function test_registered_block_schema_filters_invalid_attributes() {
		$post_id = self::factory()->post->create(
			[
				'post_type' => Plugin::POST_TYPE_AGENT_PLUGIN,
				'post_name' => 'schema-attributes',
				'post_content' => implode(
					'',
					[
						'<!-- wp:agent-pilot/agent-plugin {"license":123} -->',
						'<div class="wp-block-agent-pilot-agent-plugin">',
						'<!-- wp:agent-pilot/agent-plugin-skill {"skillId":"not-a-number"} /-->',
						'<!-- wp:agent-pilot/agent-plugin-mcp-server {"name":"example","definition":123} /-->',
						'</div><!-- /wp:agent-pilot/agent-plugin -->',
					]
				),
			]
		);
		$plugin = new Agent_Plugin( get_post( $post_id ), new Skills( Plugin::POST_TYPE_AGENT_SKILL ) );

		$this->assertArrayNotHasKey( 'license', $plugin->get_manifest(), 'Invalid manifest attributes should be removed by the registered block schema.' );
		$this->assertSame( [], $plugin->get_skills(), 'Invalid skill IDs should be removed by the registered block schema.' );
		$this->assertSame( [], $plugin->get_servers()[0]->to_array(), 'Invalid MCP definitions should fall back to the block attribute default.' );
	}
}
