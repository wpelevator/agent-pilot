<?php

namespace WPElevator\Agent_Pilot_Tests;

use WPElevator\Agent_Pilot\Agent_Plugin_Discovery;
use WPElevator\Agent_Pilot\Agent_Plugins;
use WPElevator\Agent_Pilot\Ard_Discovery;
use WPElevator\Agent_Pilot\Discovery;
use WPElevator\Agent_Pilot\Plugin;
use WPElevator\Agent_Pilot\Request;
use WPElevator\Agent_Pilot\Response;
use WPElevator\Agent_Pilot\Response_Emitter;
use WPElevator\Agent_Pilot\Skill_Post;
use WPElevator\Agent_Pilot\Skills;

class Ard_Discovery_Test extends \WP_UnitTestCase {

	private Ard_Discovery $ard;

	private Discovery $discovery;

	private Agent_Plugin_Discovery $plugin_discovery;

	private ?Response $response = null;

	public function set_up() {
		parent::set_up();

		Plugin::action_register_post_type();

		$emitter = $this->getMockBuilder( Response_Emitter::class )
			->setConstructorArgs( [ new Request() ] )
			->onlyMethods( [ 'send' ] )
			->getMock();
		$emitter
			->method( 'send' )
			->willReturnCallback(
				function ( Response $response ) use ( $emitter ): void {
					$this->response = $emitter->prepare_response( $response );
				}
			);

		$skills = new Skills( Plugin::POST_TYPE_AGENT_SKILL );
		$plugins = new Agent_Plugins();

		$this->discovery = new Discovery( $skills, $emitter );
		$this->plugin_discovery = new Agent_Plugin_Discovery( $plugins, $emitter );
		$this->ard = new Ard_Discovery( $skills, $plugins, $this->discovery, $this->plugin_discovery, $emitter );
	}

	public function test_registers_the_well_known_manifest_rewrite_rule() {
		global $wp_rewrite;

		$this->ard->action_add_rewrite_rules();

		$this->assertSame(
			'index.php?' . Ard_Discovery::QUERY_MANIFEST . '=1',
			$wp_rewrite->extra_rules_top['^\.well-known/ard\.json$'],
			'The manifest should be routed from the well-known path outside wp-json.'
		);
	}

	public function test_registers_its_query_variable() {
		$this->assertSame(
			[ 'existing', Ard_Discovery::QUERY_MANIFEST ],
			$this->ard->filter_query_vars( [ 'existing' ] ),
			'The manifest should preserve existing query variables and add its own selector.'
		);
	}

	public function test_advertises_the_manifest_with_a_link_tag() {
		ob_start();
		$this->ard->action_print_link_tag();

		$this->assertSame(
			sprintf( '<link rel="ard" href="%s">', home_url( Ard_Discovery::MANIFEST_PATH ) ) . "\n",
			ob_get_clean(),
			'Pages should advertise the manifest with the rel="ard" link the specification defines.'
		);
	}

	public function test_lists_published_skills_and_plugins_with_domain_anchored_identifiers() {
		$this->create_skill( 'alpha-skill', 'Alpha description.', 'publish' );
		$this->create_plugin( 'bundle-plugin', 'publish' );

		$entries = $this->ard->get_manifest()['entries'];

		$this->assertSame(
			[
				'urn:air:example.org:skill:alpha-skill',
				'urn:air:example.org:plugin:bundle-plugin',
			],
			wp_list_pluck( $entries, 'identifier' ),
			'Identifiers should anchor to the site domain and separate skills from plugins by namespace.'
		);
	}

	public function test_skill_entries_point_at_the_archive_the_skills_index_serves() {
		$skill = Skill_Post::from_post_id( $this->create_skill( 'alpha-skill', 'Alpha description.', 'publish' ) );

		$entry = $this->ard->get_manifest()['entries'][0];

		$this->assertSame( Ard_Discovery::TYPE_SKILL, $entry['type'], 'A skill should be typed as a skill archive.' );
		$this->assertSame( $this->discovery->get_skill_zip_url( $skill ), $entry['url'], 'The entry should reference the same archive the skills index publishes.' );
		$this->assertSame( 'Alpha Skill', $entry['displayName'], 'The display name should be the post title.' );
		$this->assertSame( 'Alpha description.', $entry['description'], 'The description should be the skill description.' );
		$this->assertArrayNotHasKey( 'data', $entry, 'An entry must carry a url or data but never both.' );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $entry['updatedAt'], 'The update time should be an RFC 3339 date-time.' );
	}

	public function test_plugin_entries_point_at_the_plugin_archive() {
		$this->create_plugin( 'bundle-plugin', 'publish' );

		$entry = $this->ard->get_manifest()['entries'][0];

		$this->assertSame( Ard_Discovery::TYPE_PLUGIN, $entry['type'], 'An Agent Plugin should be typed as a plugin archive.' );
		$this->assertStringEndsWith( 'plugin.zip', $entry['url'], 'The entry should reference the plugin archive.' );
	}

	public function test_omits_drafts_and_invalid_plugins() {
		$this->create_skill( 'draft-skill', 'Draft.', 'draft' );
		$this->create_plugin( 'draft-plugin', 'draft' );
		$this->create_plugin( 'empty-plugin', 'publish', [] );

		$this->assertSame( [], $this->ard->get_manifest()['entries'], 'Unpublished packages and plugins that fail validation must not be advertised.' );
	}

	public function test_omits_empty_optional_members() {
		$this->create_skill( 'bare-skill', '', 'publish' );

		$this->assertArrayNotHasKey( 'description', $this->ard->get_manifest()['entries'][0], 'An empty description should be left out rather than published blank.' );
	}

	public function test_entries_are_filterable() {
		add_filter(
			'agent_pilot__ard_entries',
			fn ( array $entries ): array => array_merge( $entries, [ [ 'identifier' => 'urn:air:example.org:extra:one' ] ] )
		);

		$this->assertSame( 'urn:air:example.org:extra:one', $this->ard->get_manifest()['entries'][0]['identifier'], 'Integrations should be able to add entries.' );
	}

	/**
	 * @dataProvider unsupported_host_provider
	 */
	public function test_publishes_no_entries_for_a_host_that_cannot_be_an_identifier_publisher( string $home ) {
		$this->create_skill( 'alpha-skill', 'Alpha description.', 'publish' );
		add_filter( 'home_url', fn (): string => $home );

		$this->assertNull( $this->ard->get_publisher(), 'A host with invalid characters cannot anchor a urn:air identifier.' );
		$this->assertSame( [], $this->ard->get_manifest()['entries'], 'Entries with invalid identifiers would fail validation, so none should be published.' );
	}

	public function unsupported_host_provider(): array {
		return [
			'host with an underscore' => [ 'http://my_site.test' ],
		];
	}

	public function test_identifiers_leave_out_the_port() {
		add_filter( 'home_url', fn (): string => 'http://example.org:8080' );

		$this->assertSame( 'example.org', $this->ard->get_publisher(), 'The port is not part of the domain an identifier is anchored to.' );
	}

	public function test_serves_the_manifest_as_json() {
		$this->create_skill( 'alpha-skill', 'Alpha description.', 'publish' );
		set_query_var( Ard_Discovery::QUERY_MANIFEST, '1' );

		$this->ard->action_serve_manifest();

		$this->assertInstanceOf( Response::class, $this->response, 'The manifest request should be answered.' );
		$this->assertSame( 200, $this->response->get_status(), 'The manifest should be served successfully.' );
		$this->assertStringStartsWith( 'application/json', $this->response->get_header( 'Content-Type' ), 'The manifest should be served as JSON.' );
		$this->assertSame( '*', $this->response->get_header( 'Access-Control-Allow-Origin' ), 'Agents running in a browser should be able to read the manifest.' );
		$this->assertSame(
			[ 'urn:air:example.org:skill:alpha-skill' ],
			wp_list_pluck( json_decode( $this->response->get_body(), true )['entries'], 'identifier' ),
			'The served document should hold the manifest entries.'
		);
	}

	public function test_does_not_answer_other_requests() {
		$this->ard->action_serve_manifest();

		$this->assertNull( $this->response, 'Requests that did not ask for the manifest should be left alone.' );
	}

	private function create_skill( string $name, string $description, string $status ): int {
		return self::factory()->post->create(
			[
				'post_type' => Plugin::POST_TYPE_AGENT_SKILL,
				'post_name' => $name,
				'post_title' => ucwords( str_replace( '-', ' ', $name ) ),
				'post_excerpt' => $description,
				'post_content' => '<!-- wp:paragraph --><p>Follow these instructions.</p><!-- /wp:paragraph -->',
				'post_status' => $status,
			]
		);
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
				'post_content' => wp_slash( $content ),
			]
		);
	}
}
