<?php

namespace WPElevator\Agent_Pilot_Tests;

use WPElevator\Agent_Pilot\Discovery;
use WPElevator\Agent_Pilot\Agent_Plugin_Post;
use WPElevator\Agent_Pilot\Plugin;
use WPElevator\Agent_Pilot\Request;
use WPElevator\Agent_Pilot\Response_Emitter;
use WPElevator\Agent_Pilot\Skill_Post;
use WPElevator\Agent_Pilot\Skills;

class Plugin_Test extends \WP_UnitTestCase {

	private Plugin $plugin;

	public function set_up() {
		parent::set_up();

		$this->plugin = new Plugin( __FILE__ );
		$this->plugin->action_register_post_type();
	}

	public function test_post_type_has_public_agent_skill_permalinks_and_is_available_in_rest() {
		$post_type = get_post_type_object( Plugin::POST_TYPE_AGENT_SKILL );

		$this->assertNotNull( $post_type, 'The agent_skill post type should be registered.' );
		$this->assertTrue( $post_type->public, 'Agent skills should have public WordPress single pages.' );
		$this->assertTrue( $post_type->publicly_queryable, 'Agent skills should be available to front-end queries and Query Loop blocks.' );
		$this->assertTrue( $post_type->show_ui, 'Authors should be able to manage skills in wp-admin.' );
		$this->assertFalse( $post_type->show_in_menu, 'Agent Skills should not register their own top-level admin menu.' );
		$this->assertTrue( $post_type->show_in_admin_bar, 'The admin bar New menu should still offer Agent Skills.' );
		$this->assertTrue( $post_type->show_in_rest, 'The block editor and integrations should use the native REST API.' );
		$this->assertSame( 'agent-skills', $post_type->rest_base, 'The REST base should match the public skill terminology.' );
		$this->assertSame( Plugin::PERMALINK_PREFIX_AGENT_SKILL, $post_type->rewrite['slug'], 'Agent Skill permalinks should use the requested singular prefix.' );
		$this->assertFalse( $post_type->rewrite['with_front'], 'Agent Skill permalinks should not inherit the site front base.' );
		$this->assertSame( Plugin::PERMALINK_PREFIX_AGENT_SKILL, $post_type->query_var, 'Agent Skill plain permalinks should use the requested query variable.' );
		$this->assertTrue( post_type_supports( Plugin::POST_TYPE_AGENT_SKILL, 'revisions' ), 'Skill revisions should be enabled.' );
		$this->assertTrue( post_type_supports( Plugin::POST_TYPE_AGENT_SKILL, 'custom-fields' ), 'Agent Skill post meta should be available to the REST API for editor saves.' );
		$this->assertSame( 'edit_posts', $post_type->cap->edit_posts, 'Agent Skills should use the default WordPress post editing capability.' );
		$this->assertSame( 'publish_posts', $post_type->cap->publish_posts, 'Agent Skills should use the default WordPress post publishing capability.' );
	}

	public function test_new_skills_start_inside_a_locked_agent_skill_wrapper() {
		$post_type = get_post_type_object( Plugin::POST_TYPE_AGENT_SKILL );

		$this->assertSame(
			[
				[
					Skill_Post::BLOCK_NAME,
					[],
					[
						[ 'core/paragraph' ],
					],
				],
			],
			$post_type->template,
			'New skills should start with the Agent Skill wrapper around an empty instruction paragraph.'
		);
		$this->assertSame( 'all', $post_type->template_lock, 'The Agent Skill wrapper should not be removable or replaceable.' );
	}

	public function test_new_plugins_start_with_skill_and_mcp_placeholders() {
		$post_type = get_post_type_object( Plugin::POST_TYPE_AGENT_PLUGIN );

		$this->assertSame(
			[
				[
					Agent_Plugin_Post::BLOCK_NAME_PLUGIN,
					[],
					[
						[ Agent_Plugin_Post::BLOCK_NAME_SKILL ],
						[ Agent_Plugin_Post::BLOCK_NAME_MCP ],
					],
				],
			],
			$post_type->template,
			'New plugins should start with the wrapper, a Skill placeholder, and an MCP Server placeholder.'
		);
		$this->assertSame( 'all', $post_type->template_lock, 'The Agent Plugin wrapper should not be removable or replaceable.' );
	}

	public function test_plugin_uses_a_skills_resolver() {
		$this->assertInstanceOf( Skills::class, $this->plugin->get_skills(), 'Plugin should provide the shared skills repository.' );
	}

	public function test_init_registers_current_hooks() {
		$this->plugin->init();

		$this->assertNotFalse( has_filter( 'query_vars' ), 'Plugin initialization should boot the discovery endpoints.' );
	}

	public function test_settings_page_presents_skills_and_plugins_in_form_tables() {
		ob_start();
		$this->plugin->render_settings_page();
		$html = ob_get_clean();
		$document = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		try {
			$document->loadHTML( $html );
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
		}
		$xpath = new \DOMXPath( $document );

		$index_url = ( new Discovery( new Skills( Plugin::POST_TYPE_AGENT_SKILL ), new Response_Emitter( new Request() ) ) )->get_index_url();
		$mcp_url = $this->plugin->get_mcp_server()->get_endpoint_url();

		$this->assertSame( 3, $xpath->query( '//table[@class="form-table"]' )->length, 'Skills, Plugins and MCP Server should each use a settings form table.' );
		$this->assertSame( 1, $xpath->query( '//th[normalize-space()="Manage Skills"]' )->length, 'The skills section should offer View and Add New from a Manage Skills row.' );
		$this->assertSame( 1, $xpath->query( '//th[normalize-space()="Manage Plugins"]' )->length, 'The plugins section should offer View and Add New from a Manage Plugins row.' );
		$this->assertSame( 1, $xpath->query( '//a[@href="' . esc_url( $this->plugin->get_skills_admin_url() ) . '" and normalize-space()="View"]' )->length, 'Manage Skills should link View to the Agent Skills list.' );
		$this->assertSame( 1, $xpath->query( '//a[@href="' . esc_url( $this->plugin->get_skills_new_url() ) . '" and normalize-space()="Add New"]' )->length, 'Manage Skills should link Add New to the skill editor.' );
		$this->assertSame( 1, $xpath->query( '//a[@href="' . esc_url( $this->plugin->get_plugins_admin_url() ) . '" and normalize-space()="View"]' )->length, 'Manage Plugins should link View to the Agent Plugins list.' );
		$this->assertSame( 1, $xpath->query( '//a[@href="' . esc_url( $this->plugin->get_plugins_new_url() ) . '" and normalize-space()="Add New"]' )->length, 'Manage Plugins should link Add New to the plugin editor.' );
		$this->assertSame( 1, $xpath->query( '//input[@readonly and contains(@onfocus, "select") and @value="' . $index_url . '"]' )->length, 'The discovery index should be a readonly field that selects on focus.' );
		$this->assertSame( 1, $xpath->query( '//input[@readonly and contains(@onfocus, "select") and @value="' . $mcp_url . '"]' )->length, 'The MCP server URL should be a readonly field that selects on focus.' );
		$this->assertSame( 1, $xpath->query( '//a[@href="' . esc_url( $index_url ) . '" and @target="_blank" and normalize-space()="Open"]' )->length, 'The discovery index should have an Open link in a new window.' );
		$this->assertSame( 1, $xpath->query( '//a[@href="' . esc_url( $mcp_url ) . '" and @target="_blank" and normalize-space()="Open"]' )->length, 'The MCP server URL should have an Open link in a new window.' );
		$this->assertSame( 2, $xpath->query( '//button[contains(@onclick, "agentPilotCopyField") and normalize-space()="Copy"]' )->length, 'Each copyable URL should have a Copy button before Open.' );
		$this->assertSame( 0, $xpath->query( '//th[normalize-space()="Install"]' )->length, 'Install should live in the skills section description rather than a form table row.' );
		$this->assertSame( 1, $xpath->query( '//h2[normalize-space()="Agent Skills"]/following-sibling::p[1]//code[contains(., "npx skills add")]' )->length, 'The skills section description should include the install command.' );
		$this->assertSame( 1, $xpath->query( '//h2[normalize-space()="Agent Skills"]/following-sibling::p[1]//a[@href="https://www.npmjs.com/package/skills"]' )->length, 'The skills section description should link to the skills package.' );
	}

	public function test_plugin_action_links_point_at_the_skill_list_and_settings() {
		$actions = $this->plugin->filter_plugin_action_links( [] );

		$this->assertStringContainsString( esc_url( $this->plugin->get_skills_admin_url() ), $actions['skills'], 'The plugin list should link to the Agent Skills post list.' );
		$this->assertStringContainsString( esc_url( $this->plugin->get_plugins_admin_url() ), $actions['plugins'], 'The plugin list should link to the Agent Plugins post list.' );
		$this->assertStringContainsString( esc_url( $this->plugin->get_settings_url() ), $actions['settings'], 'The plugin list should link to the Agent Pilot settings page.' );
		$this->assertSame( admin_url( 'edit.php?post_type=' . Plugin::POST_TYPE_AGENT_SKILL ), $this->plugin->get_skills_admin_url(), 'The skills admin URL should open the Agent Skill post list.' );
		$this->assertSame( admin_url( 'admin.php?page=agent-pilot' ), $this->plugin->get_settings_url(), 'Settings should open as the Agent Pilot top-level menu page.' );
	}

	public function test_agent_plugin_post_type_does_not_register_its_own_menu() {
		$post_type = get_post_type_object( Plugin::POST_TYPE_AGENT_PLUGIN );

		$this->assertNotNull( $post_type, 'The agent_plugin post type should be registered.' );
		$this->assertTrue( $post_type->show_ui, 'Authors should be able to manage Agent Plugins in wp-admin.' );
		$this->assertFalse( $post_type->show_in_menu, 'Agent Plugins should not register their own top-level admin menu.' );
		$this->assertTrue( $post_type->show_in_admin_bar, 'The admin bar New menu should still offer Agent Plugins.' );
	}

	public function test_admin_menu_nests_settings_skills_and_plugins() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$this->register_admin_menu_for_current_user();

		$this->assertSame(
			[
				'agent-pilot',
				'edit.php?post_type=' . Plugin::POST_TYPE_AGENT_SKILL,
				'post-new.php?post_type=' . Plugin::POST_TYPE_AGENT_SKILL,
				'edit.php?post_type=' . Plugin::POST_TYPE_AGENT_PLUGIN,
				'post-new.php?post_type=' . Plugin::POST_TYPE_AGENT_PLUGIN,
			],
			$this->get_agent_pilot_submenu_slugs(),
			'Administrators should see Settings as the default Agent Pilot page, then Skills, Add Skill, Plugins and Add Plugin.'
		);
		$this->assertSame(
			[ 'Settings', 'Skills', 'Add Skill', 'Plugins', 'Add Plugin' ],
			$this->get_agent_pilot_submenu_titles(),
			'The nested items should use the short menu titles rather than the post type names.'
		);
	}

	public function test_editors_see_skills_and_plugins_without_settings() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );

		$this->register_admin_menu_for_current_user();

		$this->assertSame(
			[
				'edit.php?post_type=' . Plugin::POST_TYPE_AGENT_SKILL,
				'post-new.php?post_type=' . Plugin::POST_TYPE_AGENT_SKILL,
				'edit.php?post_type=' . Plugin::POST_TYPE_AGENT_PLUGIN,
				'post-new.php?post_type=' . Plugin::POST_TYPE_AGENT_PLUGIN,
			],
			$this->get_agent_pilot_submenu_slugs(),
			'Editors should reach Skills and Plugins under Agent Pilot without a Settings item they cannot use.'
		);
	}

	public function test_skill_screens_keep_the_agent_pilot_menu_open() {
		set_current_screen( 'edit-' . Plugin::POST_TYPE_AGENT_SKILL );

		$this->assertSame(
			'agent-pilot',
			$this->plugin->filter_parent_file( 'edit.php?post_type=' . Plugin::POST_TYPE_AGENT_SKILL ),
			'The Agent Skills list should sit under the Agent Pilot menu.'
		);

		set_current_screen( 'edit-post' );

		$this->assertSame(
			'edit.php',
			$this->plugin->filter_parent_file( 'edit.php' ),
			'Other admin screens should keep their own parent menu.'
		);
	}

	private function register_admin_menu_for_current_user(): void {
		global $menu, $submenu;

		$menu = is_array( $menu ) ? $menu : [];
		$submenu = is_array( $submenu ) ? $submenu : [];
		$menu = array_values(
			array_filter(
				$menu,
				fn ( $item ): bool => ! isset( $item[2] ) || 'agent-pilot' !== $item[2]
			)
		);
		unset( $submenu['agent-pilot'] );

		$this->plugin->action_register_admin_menu();
	}

	/**
	 * @return string[]
	 */
	private function get_agent_pilot_submenu_slugs(): array {
		return array_values( array_column( $this->get_agent_pilot_submenu(), 2 ) );
	}

	/**
	 * @return string[]
	 */
	private function get_agent_pilot_submenu_titles(): array {
		return array_values( array_column( $this->get_agent_pilot_submenu(), 0 ) );
	}

	private function get_agent_pilot_submenu(): array {
		global $submenu;

		return $submenu['agent-pilot'] ?? [];
	}

	public function test_registers_compatibility_post_meta() {
		$registered_meta = get_registered_meta_keys( 'post', Plugin::POST_TYPE_AGENT_SKILL );

		$this->assertArrayHasKey( Skill_Post::META_KEY_COMPATIBILITY, $registered_meta, 'The compatibility field should be registered as Agent Skill post meta.' );
		$this->assertSame( 'string', $registered_meta[ Skill_Post::META_KEY_COMPATIBILITY ]['type'], 'Compatibility post meta should be stored as a string.' );
		$this->assertTrue( $registered_meta[ Skill_Post::META_KEY_COMPATIBILITY ]['single'], 'Compatibility post meta should store one value per skill.' );
		$this->assertTrue( $registered_meta[ Skill_Post::META_KEY_COMPATIBILITY ]['show_in_rest'], 'Compatibility post meta should be editable through the REST API and block editor.' );
	}

	public function test_rest_api_saves_compatibility_post_meta() {
		$post_id = self::factory()->post->create(
			[
				'post_type' => Plugin::POST_TYPE_AGENT_SKILL,
				'post_status' => 'draft',
			]
		);
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$request = new \WP_REST_Request( 'POST', rest_get_route_for_post( $post_id ) );
		$request->set_body_params(
			[
				'meta' => [
					Skill_Post::META_KEY_COMPATIBILITY => 'Requires Docker and WP-CLI.',
				],
			]
		);
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status(), 'The Agent Skill REST endpoint should accept editor saves.' );
		$this->assertSame( 'Requires Docker and WP-CLI.', get_post_meta( $post_id, Skill_Post::META_KEY_COMPATIBILITY, true ), 'Compatibility meta should persist when saved through the REST API.' );
	}

	public function test_rest_response_includes_the_skill_links() {
		$post_id = self::factory()->post->create(
			[
				'post_type' => Plugin::POST_TYPE_AGENT_SKILL,
				'post_name' => 'example-skill',
				'post_status' => 'publish',
			]
		);
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->plugin->action_register_rest_fields();
		$response = rest_get_server()->dispatch( new \WP_REST_Request( 'GET', rest_get_route_for_post( $post_id ) ) );
		$discovery = new Discovery( new Skills( Plugin::POST_TYPE_AGENT_SKILL ), new Response_Emitter( new Request() ) );

		$this->assertSame( 200, $response->get_status(), 'The Agent Skill REST endpoint should return the requested skill.' );
		$this->assertSame( get_permalink( $post_id ), $response->get_data()['skill_permalink'], 'The editor should receive the regular WordPress skill permalink.' );
		$this->assertSame(
			$discovery->get_skill_md_url( Skill_Post::from_post_id( $post_id ) ),
			$response->get_data()['skill_file_url'],
			'The editor sidebar should receive the generated SKILL.md URL owned by discovery.'
		);
		$this->assertSame(
			$discovery->get_skill_zip_url( Skill_Post::from_post_id( $post_id ) ),
			$response->get_data()['skill_zip_url'],
			'The editor sidebar should receive the generated skill ZIP URL owned by discovery.'
		);
	}

	public function test_human_skill_output_renders_generated_markdown_in_a_preformatted_block() {
		if ( ! \WP_Block_Type_Registry::get_instance()->is_registered( Skill_Post::BLOCK_NAME ) ) {
			register_block_type( dirname( __DIR__, 2 ) . '/build/blocks/agent-skill' );
		}

		$post_id = self::factory()->post->create(
			[
				'post_type' => Plugin::POST_TYPE_AGENT_SKILL,
				'post_name' => 'human-preview',
				'post_title' => 'Human Preview',
				'post_excerpt' => 'Preview generated Markdown.',
				'post_content' => '<!-- wp:agent-pilot/agent-skill --><div class="wp-block-agent-pilot-agent-skill"><!-- wp:paragraph --><p>Follow the instructions.</p><!-- /wp:paragraph --></div><!-- /wp:agent-pilot/agent-skill -->',
				'post_status' => 'publish',
			]
		);
		$block = new \WP_Block(
			parse_blocks( get_post_field( 'post_content', $post_id ) )[0],
			[
				'postId' => $post_id,
				'postType' => Plugin::POST_TYPE_AGENT_SKILL,
			]
		);
		$output = $block->render();

		$this->assertStringStartsWith( '<div class="wp-block-agent-pilot-agent-skill">', $output, 'The human-facing output should preserve the Agent Skill block wrapper.' );
		$this->assertStringContainsString( '<pre class="wp-block-code">', $output, 'The human-facing block should render generated Markdown in a pre element.' );
		$this->assertStringContainsString( 'name: human-preview', $output, 'The pre element should contain the SKILL.md frontmatter.' );
		$this->assertStringContainsString( 'compatibility: &quot;&quot;', $output, 'The pre element should contain escaped SKILL.md frontmatter quoting.' );
		$this->assertStringContainsString( 'Follow the instructions.', $output, 'The pre element should contain the generated Markdown instructions.' );
		$this->assertStringNotContainsString( '<p>Follow the instructions.</p>', $output, 'Stored block HTML should not render instead of the generated Markdown preview.' );
	}

	public function test_skill_editor_keeps_the_full_block_catalog_available() {
		$skill_context = new \WP_Block_Editor_Context( [ 'post' => get_post( self::factory()->post->create( [ 'post_type' => Plugin::POST_TYPE_AGENT_SKILL ] ) ) ] );

		$this->assertTrue(
			$this->plugin->filter_allowed_block_types( true, $skill_context ),
			'Skill editor should retain the full block catalog so Reference blocks can contain blocks of any kind.'
		);
		$this->assertTrue(
			$this->plugin->filter_allowed_block_types( true, new \WP_Block_Editor_Context( [ 'post' => get_post( self::factory()->post->create() ) ] ) ),
			'Block restrictions should not affect other post types.'
		);
		$this->assertTrue(
			$this->plugin->filter_allowed_block_types( true, new \WP_Block_Editor_Context() ),
			'Block restrictions should not affect editor contexts without a post.'
		);
	}

	public function test_skill_editor_adds_the_skill_blocks_to_a_restricted_block_catalog() {
		$skill_context = new \WP_Block_Editor_Context( [ 'post' => get_post( self::factory()->post->create( [ 'post_type' => Plugin::POST_TYPE_AGENT_SKILL ] ) ) ] );
		$allowed = $this->plugin->filter_allowed_block_types( [ 'core/paragraph' ], $skill_context );

		$this->assertContains( Skill_Post::BLOCK_NAME, $allowed, 'A restricted editor should still offer the Agent Skill wrapper.' );
		$this->assertContains( Skill_Post::REFERENCE_BLOCK_NAME, $allowed, 'A restricted editor should still offer Reference resources.' );
		$this->assertContains( Skill_Post::SCRIPT_BLOCK_NAME, $allowed, 'A restricted editor should still offer Script resources.' );
		$this->assertContains( Skill_Post::ASSET_BLOCK_NAME, $allowed, 'A restricted editor should still offer Asset resources.' );
		$this->assertSame( array_values( array_unique( $allowed ) ), $allowed, 'The merged block catalog should not repeat block names.' );
		$this->assertSame(
			[ 'core/paragraph' ],
			$this->plugin->filter_allowed_block_types( [ 'core/paragraph' ], new \WP_Block_Editor_Context( [ 'post' => get_post( self::factory()->post->create() ) ] ) ),
			'A restricted catalog for other post types should be left untouched.'
		);
	}

	public function test_resource_blocks_start_with_unpopulated_filename_attributes() {
		$blocks_dir = dirname( __DIR__, 2 ) . '/js/blocks';
		$script = json_decode( (string) file_get_contents( $blocks_dir . '/agent-skill-script/block.json' ), true );
		$reference = json_decode( (string) file_get_contents( $blocks_dir . '/agent-skill-reference/block.json' ), true );
		$asset = json_decode( (string) file_get_contents( $blocks_dir . '/agent-skill-asset/block.json' ), true );

		$this->assertArrayNotHasKey( 'default', $script['attributes']['fileName'], 'New Script blocks should not prepopulate a filename.' );
		$this->assertArrayNotHasKey( 'default', $reference['attributes']['fileName'], 'New Reference blocks should not prepopulate a filename.' );
		$this->assertArrayNotHasKey( 'default', $asset['attributes']['fileName'], 'New Asset blocks should not prepopulate a filename.' );
		$this->assertSame( 'md', $reference['attributes']['format']['default'], 'New Reference blocks should publish Markdown resources by default.' );
	}
}
