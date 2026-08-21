<?php

namespace WPElevator\Agent_Pilot;

class Plugin {

	public const POST_TYPE_AGENT_SKILL = 'agent_skill';
	public const POST_TYPE_AGENT_PLUGIN = 'agent_plugin';

	public const PERMALINK_PREFIX_AGENT_PLUGIN = 'agent-plugin';
	public const PERMALINK_PREFIX_AGENT_SKILL = 'agent-skill';

	private const SETTINGS_SLUG = 'agent-pilot';

	private string $plugin_file;

	private Discovery $discovery;

	private Skills $skills;
	private Agent_Plugins $agent_plugins;
	private Agent_Plugin_Discovery $plugin_discovery;

	public function __construct( string $plugin_file ) {
		$this->plugin_file = $plugin_file;

		$this->skills = new Skills( self::POST_TYPE_AGENT_SKILL );
		$response_emitter = new Response_Emitter( Request::from_globals() );
		$this->discovery = new Discovery( $this->skills, $response_emitter );
		$this->agent_plugins = new Agent_Plugins( $this->skills );
		$this->plugin_discovery = new Agent_Plugin_Discovery( $this->agent_plugins, $response_emitter );
	}

	public function init() {
		add_action( 'init', [ $this, 'action_register_post_type' ] );
		add_action( 'init', [ $this, 'action_register_blocks' ] );
		add_action( 'rest_api_init', [ $this, 'action_register_rest_fields' ] );
		add_filter( 'allowed_block_types_all', [ $this, 'filter_allowed_block_types' ], 10, 2 );
		add_action( 'admin_menu', [ $this, 'action_register_settings_page' ] );
		add_filter( 'plugin_action_links_' . $this->get_basename(), [ $this, 'filter_plugin_action_links' ] );

		$this->discovery->init();
		$this->plugin_discovery->init();
	}

	public function get_basename(): string {
		return plugin_basename( $this->plugin_file );
	}

	public function get_skills(): Skills {
		return $this->skills;
	}

	public function get_agent_plugins(): Agent_Plugins {
		return $this->agent_plugins;
	}

	public function action_register_settings_page(): void {
		add_options_page(
			__( 'Agent Pilot', 'wpelevator-agent-pilot' ),
			__( 'Agent Pilot', 'wpelevator-agent-pilot' ),
			'manage_options',
			self::SETTINGS_SLUG,
			[ $this, 'render_settings_page' ]
		);
	}

	public function filter_plugin_action_links( array $actions ): array {
		$actions['skills'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( $this->get_skills_admin_url() ),
			esc_html__( 'Skills', 'wpelevator-agent-pilot' )
		);
		$actions['plugins'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( $this->get_plugins_admin_url() ),
			esc_html__( 'Plugins', 'wpelevator-agent-pilot' )
		);

		$actions['settings'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( $this->get_settings_url() ),
			esc_html__( 'Settings', 'wpelevator-agent-pilot' )
		);

		return $actions;
	}

	public function render_settings_page(): void {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Agent Pilot', 'wpelevator-agent-pilot' ); ?></h1>
			<h2><?php esc_html_e( 'Agent Skills', 'wpelevator-agent-pilot' ); ?></h2>
			<p><?php esc_html_e( 'Install the published skills with:', 'wpelevator-agent-pilot' ); ?> <code>npx skills add <?php echo esc_html( home_url() ); ?></code></p>
			<p><?php esc_html_e( 'Agent Skills discovery index:', 'wpelevator-agent-pilot' ); ?> <a href="<?php echo esc_url( $this->discovery->get_index_url() ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $this->discovery->get_index_url() ); ?></a></p>
			<h2><?php esc_html_e( 'Agent Plugins', 'wpelevator-agent-pilot' ); ?></h2>
			<p><?php esc_html_e( 'Create portable Agent Plugin packages, then download and extract the generated ZIP with a compatible installer.', 'wpelevator-agent-pilot' ); ?></p>
			<p><a href="<?php echo esc_url( $this->get_plugins_admin_url() ); ?>"><?php esc_html_e( 'Manage Agent Plugins', 'wpelevator-agent-pilot' ); ?></a></p>
		</div>
		<?php
	}

	public function get_settings_url(): string {
		return admin_url( 'options-general.php?page=' . self::SETTINGS_SLUG );
	}

	public function get_skills_admin_url(): string {
		return admin_url( 'edit.php?post_type=' . self::POST_TYPE_AGENT_SKILL );
	}

	public function get_plugins_admin_url(): string {
		return admin_url( 'edit.php?post_type=' . self::POST_TYPE_AGENT_PLUGIN );
	}

	public function action_register_rest_fields(): void {
		register_rest_field(
			self::POST_TYPE_AGENT_SKILL,
			'skill_permalink',
			[
				'get_callback' => function ( array $post ): ?string {
					$skill = Skill::from_post_id( (int) $post['id'] );

					if ( $skill ) {
						return $skill->get_permalink();
					}

					return null;
				},
				'schema' => [
					'description' => __( 'Absolute permalink for the human-readable Agent Skill.', 'wpelevator-agent-pilot' ),
					'type' => 'string',
					'format' => 'uri',
					'context' => [ 'view', 'edit' ],
					'readonly' => true,
				],
			]
		);
		foreach ( [
			'plugin_permalink' => fn( Agent_Plugin $plugin ): ?string => $plugin->get_permalink(),
			'plugin_manifest_url' => fn( Agent_Plugin $plugin ): ?string => $this->plugin_discovery->get_plugin_json_url( $plugin ),
			'plugin_mcp_url' => fn( Agent_Plugin $plugin ): ?string => $this->plugin_discovery->get_mcp_json_url( $plugin ),
			'plugin_package_url' => fn( Agent_Plugin $plugin ): ?string => $this->plugin_discovery->get_plugin_zip_url( $plugin ),
		] as $field => $callback ) {
			register_rest_field(
				self::POST_TYPE_AGENT_PLUGIN,
				$field,
				[
					'get_callback' => function ( array $post ) use ( $callback ): ?string {
						return $callback( new Agent_Plugin( get_post( (int) $post['id'] ), $this->skills ) );
					},
					'schema' => [
						'type' => 'string',
						'format' => 'uri',
						'context' => [ 'view', 'edit' ],
						'readonly' => true,
					],
				]
			);
		}
		register_rest_field(
			self::POST_TYPE_AGENT_PLUGIN,
			'plugin_validation',
			[
				'get_callback' => function ( array $post ): array {
					return ( new Agent_Plugin( get_post( (int) $post['id'] ), $this->skills ) )->get_errors();
				},
				'schema' => [
					'type' => 'array',
					'context' => [ 'edit' ],
					'readonly' => true,
				],
			]
		);

		register_rest_field(
			self::POST_TYPE_AGENT_SKILL,
			'skill_file_url',
			[
				'get_callback' => function ( array $post ): ?string {
					$skill = Skill::from_post_id( (int) $post['id'] );

					if ( $skill ) {
						return $this->discovery->get_skill_md_url( $skill );
					}

					return null;
				},
				'schema' => [
					'description' => __( 'Absolute URL for the generated SKILL.md file.', 'wpelevator-agent-pilot' ),
					'type' => 'string',
					'format' => 'uri',
					'context' => [ 'view', 'edit' ],
					'readonly' => true,
				],
			]
		);
		register_rest_field(
			self::POST_TYPE_AGENT_SKILL,
			'skill_zip_url',
			[
				'get_callback' => function ( array $post ): ?string {
					$skill = Skill::from_post_id( (int) $post['id'] );

					if ( $skill ) {
						return $this->discovery->get_skill_zip_url( $skill );
					}

					return null;
				},
				'schema' => [
					'description' => __( 'Absolute URL for the generated skill ZIP archive.', 'wpelevator-agent-pilot' ),
					'type' => 'string',
					'format' => 'uri',
					'context' => [ 'view', 'edit' ],
					'readonly' => true,
				],
			]
		);
	}

	public static function action_register_post_type() {
		register_post_type(
			self::POST_TYPE_AGENT_SKILL,
			[
				'labels' => [
					'name' => __( 'Agent Skills', 'wpelevator-agent-pilot' ),
					'singular_name' => __( 'Agent Skill', 'wpelevator-agent-pilot' ),
					'add_new_item' => __( 'Add New Agent Skill', 'wpelevator-agent-pilot' ),
					'edit_item' => __( 'Edit Agent Skill', 'wpelevator-agent-pilot' ),
					'new_item' => __( 'New Agent Skill', 'wpelevator-agent-pilot' ),
					'view_item' => __( 'View Agent Skill', 'wpelevator-agent-pilot' ),
					'search_items' => __( 'Search Agent Skills', 'wpelevator-agent-pilot' ),
					'not_found' => __( 'No agent skills found.', 'wpelevator-agent-pilot' ),
					'not_found_in_trash' => __( 'No agent skills found in Trash.', 'wpelevator-agent-pilot' ),
					'all_items' => __( 'Agent Skills', 'wpelevator-agent-pilot' ),
					'menu_name' => __( 'Agent Skills', 'wpelevator-agent-pilot' ),
				],
				'public' => true, // TODO: Consider a setting to keep the posts private but expose only skill markdown.
				'publicly_queryable' => true,
				'show_in_rest' => true,
				'rest_base' => 'agent-skills',
				'show_ui' => true, // Always allow managing skills.
				'menu_icon' => 'dashicons-format-quote',
				'rewrite' => [
					'slug' => self::PERMALINK_PREFIX_AGENT_SKILL,
					'with_front' => false,
				],
				'query_var' => self::PERMALINK_PREFIX_AGENT_SKILL,
				'supports' => [ 'title', 'editor', 'excerpt', 'author', 'revisions', 'custom-fields' ],
				'template' => [
					[
						Skill::BLOCK_NAME,
						[],
						[
							[ 'core/paragraph' ],
						],
					],
				],
				'template_lock' => 'all',
			]
		);

		register_post_meta(
			self::POST_TYPE_AGENT_SKILL,
			Skill::META_KEY_COMPATIBILITY,
			[
				'type' => 'string',
				'description' => __( 'Agent Skills compatibility requirements.', 'wpelevator-agent-pilot' ),
				'single' => true,
				'show_in_rest' => true,
			]
		);

		register_post_type(
			self::POST_TYPE_AGENT_PLUGIN,
			[
				'labels' => [
					'name' => __( 'Agent Plugins', 'wpelevator-agent-pilot' ),
					'singular_name' => __( 'Agent Plugin', 'wpelevator-agent-pilot' ),
					'add_new_item' => __( 'Add New Agent Plugin', 'wpelevator-agent-pilot' ),
					'edit_item' => __( 'Edit Agent Plugin', 'wpelevator-agent-pilot' ),
					'menu_name' => __( 'Agent Plugins', 'wpelevator-agent-pilot' ),
				],
				'public' => true,
				'publicly_queryable' => true,
				'show_in_rest' => true,
				'rest_base' => 'agent-plugins',
				'show_ui' => true,
				'menu_icon' => 'dashicons-media-archive',
				'rewrite' => [
					'slug' => self::PERMALINK_PREFIX_AGENT_PLUGIN,
					'with_front' => false,
				],
				'query_var' => self::PERMALINK_PREFIX_AGENT_PLUGIN,
				'supports' => [ 'title', 'editor', 'excerpt', 'author', 'revisions' ],
				'template' => [
					[
						Agent_Plugin::BLOCK_NAME_PLUGIN,
						[],
						[
							[ Agent_Plugin::BLOCK_NAME_SKILL ],
							[ Agent_Plugin::BLOCK_NAME_MCP ],
						],
					],
				],
				'template_lock' => 'all',
			]
		);
	}

	private function get_path_to( ?string $relative_path = null ): string {
		if ( isset( $relative_path ) ) {
			return sprintf(
				'%s/%s',
				dirname( $this->plugin_file ),
				ltrim( $relative_path, '/' )
			);
		}

		return dirname( $this->plugin_file );
	}

	private function get_url_to( ?string $relative_path = null ): string {
		if ( isset( $relative_path ) ) {
			return sprintf(
				'%s/%s',
				plugins_url( '', $this->plugin_file ),
				ltrim( $relative_path, '/' )
			);
		}

		return plugins_url( '', $this->plugin_file );
	}

	public function action_register_blocks() {
		foreach ( glob( $this->get_path_to( 'build/blocks/*/block.json' ) ) as $block_json_file ) {
			register_block_type( $block_json_file );
		}
	}

	public function filter_allowed_block_types( $allowed_block_types, \WP_Block_Editor_Context $block_editor_context ) {
		if ( empty( $block_editor_context->post ) || ! in_array( $block_editor_context->post->post_type, [ self::POST_TYPE_AGENT_SKILL, self::POST_TYPE_AGENT_PLUGIN ], true ) ) {
			return $allowed_block_types;
		}

		if ( true === $allowed_block_types ) {
			return true;
		}

		$blocks = self::POST_TYPE_AGENT_SKILL === $block_editor_context->post->post_type
			? array_merge( [ Skill::BLOCK_NAME ], Skill::ALLOWED_BLOCKS )
			: [ Agent_Plugin::BLOCK_NAME_PLUGIN, Agent_Plugin::BLOCK_NAME_SKILL, Agent_Plugin::BLOCK_NAME_MCP ];
		return array_values(
			array_unique(
				array_merge( (array) $allowed_block_types, $blocks )
			)
		);
	}

	public static function activate(): void {
		flush_rewrite_rules(); // FIXME: this is probably firing before the post type is registered.
	}

	public static function deactivate(): void {
		flush_rewrite_rules();
	}
}
