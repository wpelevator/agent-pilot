<?php

namespace WPElevator\Agent_Pilot;

class Plugin {
	public const POST_TYPE_AGENT_SKILL = 'agent_skill';
	public const POST_TYPE_AGENT_PLUGIN = 'agent_plugin';

	public const PERMALINK_PREFIX_AGENT_PLUGIN = 'agent-plugin';
	public const PERMALINK_PREFIX_AGENT_SKILL = 'agent-skill';

	/**
	 * The one Abilities API category every Agent Pilot ability is registered in.
	 *
	 * A category may be registered only once, so it belongs here rather than to
	 * any of the components that register abilities into it.
	 */
	public const ABILITY_CATEGORY = 'agent-pilot';

	/**
	 * The names this plugin's abilities are registered under.
	 *
	 * The classes that define them know nothing about these: each returns the
	 * arguments for one ability, and the names are bound in
	 * `action_register_abilities()`, so the whole surface this plugin adds to a
	 * site can be read in one place and renamed without touching the definition.
	 */
	public const ABILITY_LIST_SKILLS = 'agent-pilot/list-agent-skills';
	public const ABILITY_GET_SKILL = 'agent-pilot/get-agent-skill';
	public const ABILITY_LIST_PLUGINS = 'agent-pilot/list-agent-plugins';
	public const ABILITY_GET_PLUGIN = 'agent-pilot/get-agent-plugin';
	public const ABILITY_REST_CALL = 'agent-pilot/rest-call';

	/**
	 * The collections the MCP resource URIs address packages under.
	 */
	public const RESOURCE_COLLECTION_SKILLS = 'skills';
	public const RESOURCE_COLLECTION_PLUGINS = 'plugins';

	private const SETTINGS_SLUG = 'agent-pilot';

	private string $plugin_file;

	private Discovery $discovery;

	private Skills $skills;
	private Agent_Plugins $agent_plugins;
	private Agent_Plugin_Discovery $plugin_discovery;

	private MCP\Settings $mcp_settings;
	private MCP\Server $mcp_server;

	public function __construct( string $plugin_file ) {
		$this->plugin_file = $plugin_file;

		$this->skills = new Skills( self::POST_TYPE_AGENT_SKILL );
		$response_emitter = new Response_Emitter( Request::from_globals() );
		$this->discovery = new Discovery( $this->skills, $response_emitter );
		$this->agent_plugins = new Agent_Plugins();
		$this->plugin_discovery = new Agent_Plugin_Discovery( $this->agent_plugins, $response_emitter );

		$this->mcp_settings = new MCP\Settings();
		$this->mcp_server = new MCP\Server(
			new MCP\Tools( $this->mcp_settings ),
			new MCP\Resources(
				/*
				 * Resolved per request rather than at construction, so that a
				 * listing reflects who is asking: each collection holds only the
				 * packages the current caller is allowed to see.
				 */
				fn (): array => [
					self::RESOURCE_COLLECTION_SKILLS => $this->skills->get_readable_skills(),
					self::RESOURCE_COLLECTION_PLUGINS => $this->agent_plugins->get_readable_plugins(),
				]
			),
			new MCP\Authentication(),
			new MCP\Sessions(),
			$this->mcp_settings,
			$this->get_version()
		);
	}

	public function init(): void {
		add_action( 'init', [ $this, 'action_register_post_type' ] );
		add_action( 'init', [ $this, 'action_register_blocks' ] );
		add_action( 'wp_abilities_api_categories_init', [ $this, 'action_register_ability_category' ] );
		add_action( 'wp_abilities_api_init', [ $this, 'action_register_abilities' ] );
		add_action( 'rest_api_init', [ $this, 'action_register_rest_fields' ] );
		add_filter( 'allowed_block_types_all', [ $this, 'filter_allowed_block_types' ], 10, 2 );
		add_action( 'admin_menu', [ $this, 'action_register_admin_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'action_register_settings_assets' ] );
		add_filter( 'parent_file', [ $this, 'filter_parent_file' ] );
		add_filter( 'plugin_action_links_' . $this->get_basename(), [ $this, 'filter_plugin_action_links' ] );

		$this->discovery->init();
		$this->plugin_discovery->init();
		$this->mcp_server->init();
	}

	public function get_basename(): string {
		return plugin_basename( $this->plugin_file );
	}

	public function get_version(): string {
		$data = get_file_data( $this->plugin_file, [ 'version' => 'Version' ] );

		return (string) ( $data['version'] ?? '' );
	}

	public function get_mcp_server(): MCP\Server {
		return $this->mcp_server;
	}

	public function get_mcp_settings(): MCP\Settings {
		return $this->mcp_settings;
	}

	public function get_skills(): Skills {
		return $this->skills;
	}

	public function get_agent_plugins(): Agent_Plugins {
		return $this->agent_plugins;
	}

	private function get_license_key(): ?string {
		return null; // TODO: get this from settings.
	}

	public function action_register_abilities(): void {
		$skills = new Skill_Abilities( $this->skills, $this->discovery );
		$plugins = new Agent_Plugin_Abilities( $this->agent_plugins, $this->plugin_discovery );

		$abilities = [
			self::ABILITY_LIST_SKILLS => $skills->get_list_args(),
			self::ABILITY_GET_SKILL => $skills->get_read_args(),
			self::ABILITY_LIST_PLUGINS => $plugins->get_list_args(),
			self::ABILITY_GET_PLUGIN => $plugins->get_read_args(),
			self::ABILITY_REST_CALL => ( new Rest_Ability() )->get_args(),
		];

		foreach ( $abilities as $name => $args ) {
			wp_register_ability( $name, $args );
		}
	}

	public function action_register_ability_category(): void {
		wp_register_ability_category(
			self::ABILITY_CATEGORY,
			[
				'label' => __( 'Agent Pilot', 'wpelevator-agent-pilot' ),
				'description' => __( 'Interact with this WordPress site.', 'wpelevator-agent-pilot' ),
			]
		);
	}

	public function action_register_admin_menu(): void {
		add_menu_page(
			__( 'Agent Pilot', 'wpelevator-agent-pilot' ),
			__( 'Agent Pilot', 'wpelevator-agent-pilot' ),
			'edit_posts',
			self::SETTINGS_SLUG,
			[ $this, 'render_settings_page' ],
			'dashicons-format-chat'
		);

		add_submenu_page(
			self::SETTINGS_SLUG,
			__( 'Agent Pilot Settings', 'wpelevator-agent-pilot' ),
			__( 'Settings', 'wpelevator-agent-pilot' ),
			'manage_options',
			self::SETTINGS_SLUG,
			[ $this, 'render_settings_page' ]
		);

		$this->add_post_type_submenu(
			self::POST_TYPE_AGENT_SKILL,
			__( 'Skills', 'wpelevator-agent-pilot' ),
			__( 'Add Skill', 'wpelevator-agent-pilot' )
		);
		$this->add_post_type_submenu(
			self::POST_TYPE_AGENT_PLUGIN,
			__( 'Plugins', 'wpelevator-agent-pilot' ),
			__( 'Add Plugin', 'wpelevator-agent-pilot' )
		);

		if ( ! current_user_can( 'manage_options' ) ) {
			remove_submenu_page( self::SETTINGS_SLUG, self::SETTINGS_SLUG );
		}
	}

	/**
	 * Keep the Agent Pilot menu open on the nested post type screens.
	 *
	 * WordPress highlights the post type list as a top-level parent, which
	 * those post types no longer have once they sit under this menu.
	 */
	public function filter_parent_file( $parent_file ) {
		$screen = get_current_screen();

		if ( $screen instanceof \WP_Screen && in_array( $screen->post_type, [ self::POST_TYPE_AGENT_SKILL, self::POST_TYPE_AGENT_PLUGIN ], true ) ) {
			$parent_file = self::SETTINGS_SLUG;
		}

		return $parent_file;
	}

	private function add_post_type_submenu( string $post_type, string $list_title, string $add_title ): void {
		$object = get_post_type_object( $post_type );

		if ( $object ) {
			add_submenu_page(
				self::SETTINGS_SLUG,
				$object->labels->name,
				$list_title,
				$object->cap->edit_posts,
				sprintf( 'edit.php?post_type=%s', $post_type )
			);
			add_submenu_page(
				self::SETTINGS_SLUG,
				$object->labels->add_new_item,
				$add_title,
				$object->cap->create_posts,
				sprintf( 'post-new.php?post_type=%s', $post_type )
			);
		}
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

	public function action_register_settings_assets(): void {
		wp_register_script( 'agent-pilot-settings', false, [], $this->get_version(), true );
		wp_add_inline_script(
			'agent-pilot-settings',
			'function agentPilotCopyField( button ) {
	var input = button.previousElementSibling;
	if ( input && input.select ) {
		input.focus();
		input.select();
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( input.value );
		} else {
			document.execCommand( "copy" );
		}
	}
}'
		);
	}

	public function render_settings_page(): void {
		wp_enqueue_script( 'agent-pilot-settings' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Agent Pilot', 'wpelevator-agent-pilot' ); ?></h1>
			<h2><?php esc_html_e( 'Agent Skills', 'wpelevator-agent-pilot' ); ?></h2>
			<p>
				<?php
				printf(
					/* translators: 1: CLI install command wrapped in code tags, 2: opening anchor tag to the skills npm package, 3: closing anchor tag */
					esc_html__( 'Use %1$s to install the skills using the %2$sskills package%3$s.', 'wpelevator-agent-pilot' ),
					'<code>npx skills add ' . esc_html( home_url() ) . '</code>',
					'<a href="' . esc_url( 'https://www.npmjs.com/package/skills' ) . '" target="_blank" rel="noopener noreferrer">',
					'</a>'
				);
				?>
			</p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Manage Skills', 'wpelevator-agent-pilot' ); ?></th>
					<td>
						<?php $this->render_manage_buttons( $this->get_skills_admin_url(), $this->get_skills_new_url() ); ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Discovery Index', 'wpelevator-agent-pilot' ); ?></th>
					<td>
						<?php
						$this->render_copyable_url(
							$this->discovery->get_index_url(),
							__( 'Well-known index of the Agent Skills published on this site.', 'wpelevator-agent-pilot' )
						);
						?>
					</td>
				</tr>
			</table>
			<h2><?php esc_html_e( 'Agent Plugins', 'wpelevator-agent-pilot' ); ?></h2>
			<p><?php esc_html_e( 'Create portable Agent Plugin packages, then download and extract the generated ZIP with a compatible installer.', 'wpelevator-agent-pilot' ); ?></p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Manage Plugins', 'wpelevator-agent-pilot' ); ?></th>
					<td>
						<?php $this->render_manage_buttons( $this->get_plugins_admin_url(), $this->get_plugins_new_url() ); ?>
						<p class="description"><?php esc_html_e( 'Compose published Agent Skills and optional MCP server definitions into a package.', 'wpelevator-agent-pilot' ); ?></p>
					</td>
				</tr>
			</table>
			<?php $this->render_mcp_settings(); ?>
		</div>
		<?php
	}

	private function render_mcp_settings(): void {
		$enabled_option = $this->mcp_settings->get_option_name( 'mcp_enabled' );
		$authentication = new MCP\Authentication();
		$tools = new MCP\Tools( $this->mcp_settings );
		?>
		<h2><?php esc_html_e( 'MCP Server', 'wpelevator-agent-pilot' ); ?></h2>
		<p><?php esc_html_e( 'Publish the available WordPress Abilities as MCP tools so that agent clients can call them directly.', 'wpelevator-agent-pilot' ); ?></p>

		<?php if ( ! $tools->is_available() ) : ?>
			<div class="notice notice-warning inline">
				<p><?php esc_html_e( 'This site does not have the WordPress Abilities API, which requires WordPress 6.9 or newer. The MCP server has nothing to expose until it is available.', 'wpelevator-agent-pilot' ); ?></p>
			</div>
		<?php endif; ?>

		<?php if ( ! $authentication->is_oauth_available() ) : ?>
			<div class="notice notice-info inline">
				<p>
				<?php
					echo sprintf(
						/* translators: %s: OAuth Pilot plugin link */
						esc_html__( 'Install and activate %s to enable simple authentication for all major AI apps and clients such as ChatGPT and Claude.', 'wpelevator-agent-pilot' ),
						sprintf( '<a href="https://wpelevator.com/plugins/oauth-pilot">OAuth Pilot</a>' )
					);
				?>
				</p>
			</div>
		<?php endif; ?>

		<form action="options.php" method="post">
			<?php settings_fields( MCP\Settings::GROUP ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'MCP Server', 'wpelevator-agent-pilot' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $enabled_option ); ?>" value="1" <?php checked( $this->mcp_settings->is_mcp_enabled() ); ?> />
							<?php esc_html_e( 'Enable MCP server', 'wpelevator-agent-pilot' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Abilities are exposed only when they opt in with the mcp.public meta flag, and every call still runs the ability\'s own permission check.', 'wpelevator-agent-pilot' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'MCP Server Address', 'wpelevator-agent-pilot' ); ?></th>
					<td>
						<?php
						$this->render_copyable_url(
							$this->mcp_server->get_endpoint_url(),
							__( 'URL of the MCP server. Clients that support OAuth discover the rest on their own.', 'wpelevator-agent-pilot' )
						);
						?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'MCP Tools', 'wpelevator-agent-pilot' ); ?></th>
					<td><?php $this->render_mcp_disabled_abilities( $tools ); ?></td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
		<?php
	}

	private function render_mcp_disabled_abilities( MCP\Tools $tools ): void {
		$option = $this->mcp_settings->get_option_name( 'mcp_disabled_abilities' );
		$disabled = $this->mcp_settings->get_disabled_abilities();
		$abilities = $tools->get_available_abilities();

		// Keep saved exclusions visible when the providing plugin is inactive.
		$names = array_unique( array_merge( array_keys( $abilities ), $disabled ) );
		sort( $names );

		?>
		<p class="description"><?php esc_html_e( 'Select the registered WordPress abilities to disable in MCP clients and prevent them from calling as tools.', 'wpelevator-agent-pilot' ); ?></p>
		<input type="hidden" name="<?php echo esc_attr( $option ); ?>[]" value="" />
		<ul>
			<?php foreach ( $names as $name ) : ?>
			<li>
				<label>
					<input type="checkbox" name="<?php echo esc_attr( $option ); ?>[]" value="<?php echo esc_attr( $name ); ?>" <?php checked( in_array( $name, $disabled, true ) ); ?> />
					<?php echo esc_html( isset( $abilities[ $name ] ) ? $abilities[ $name ]->get_label() : $name ); ?>
					<code><?php echo esc_html( $name ); ?></code>
				</label>
			</li>
			<?php endforeach; ?>
			<?php if ( empty( $names ) ) : ?>
				<li><?php esc_html_e( 'No abilities are available for MCP mapping.', 'wpelevator-agent-pilot' ); ?></li>
			<?php endif; ?>
		</ul>
		<?php
	}

	public function get_settings_url(): string {
		return admin_url( 'admin.php?page=' . self::SETTINGS_SLUG );
	}

	public function get_skills_admin_url(): string {
		return admin_url( 'edit.php?post_type=' . self::POST_TYPE_AGENT_SKILL );
	}

	public function get_skills_new_url(): string {
		return admin_url( 'post-new.php?post_type=' . self::POST_TYPE_AGENT_SKILL );
	}

	public function get_plugins_admin_url(): string {
		return admin_url( 'edit.php?post_type=' . self::POST_TYPE_AGENT_PLUGIN );
	}

	public function get_plugins_new_url(): string {
		return admin_url( 'post-new.php?post_type=' . self::POST_TYPE_AGENT_PLUGIN );
	}

	private function render_copyable_url( string $url, string $description ): void {
		?>
		<input type="text" class="regular-text code" value="<?php echo esc_attr( $url ); ?>" readonly onfocus="this.select();" />
		<button type="button" class="button" onclick="agentPilotCopyField(this);"><?php esc_html_e( 'Copy', 'wpelevator-agent-pilot' ); ?></button>
		<a href="<?php echo esc_url( $url ); ?>" class="button-link" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open', 'wpelevator-agent-pilot' ); ?></a>
		<p class="description"><?php echo esc_html( $description ); ?></p>
		<?php
	}

	private function render_manage_buttons( string $list_url, string $new_url ): void {
		?>
		<a href="<?php echo esc_url( $list_url ); ?>" class="button"><?php esc_html_e( 'View', 'wpelevator-agent-pilot' ); ?></a>
		<a href="<?php echo esc_url( $new_url ); ?>" class="button"><?php esc_html_e( 'Add New', 'wpelevator-agent-pilot' ); ?></a>
		<?php
	}

	public function action_register_rest_fields(): void {
		register_rest_field(
			self::POST_TYPE_AGENT_SKILL,
			'skill_permalink',
			[
				'get_callback' => function ( array $post ): ?string {
					$skill = Skill_Post::from_post_id( (int) $post['id'] );

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
			'plugin_permalink' => fn( Agent_Plugin_Post $plugin ): ?string => $plugin->get_permalink(),
			'plugin_manifest_url' => fn( Agent_Plugin_Post $plugin ): ?string => $this->plugin_discovery->get_plugin_json_url( $plugin ),
			'plugin_mcp_url' => fn( Agent_Plugin_Post $plugin ): ?string => $this->plugin_discovery->get_mcp_json_url( $plugin ),
			'plugin_package_url' => fn( Agent_Plugin_Post $plugin ): ?string => $this->plugin_discovery->get_plugin_zip_url( $plugin ),
		] as $field => $callback ) {
			register_rest_field(
				self::POST_TYPE_AGENT_PLUGIN,
				$field,
				[
					'get_callback' => function ( array $post ) use ( $callback ): ?string {
						return $callback( new Agent_Plugin_Post( get_post( (int) $post['id'] ) ) );
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
					return ( new Agent_Plugin_Post( get_post( (int) $post['id'] ) ) )->get_errors();
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
					$skill = Skill_Post::from_post_id( (int) $post['id'] );

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
					$skill = Skill_Post::from_post_id( (int) $post['id'] );

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
				'show_in_menu' => false,
				'show_in_admin_bar' => true,
				'rewrite' => [
					'slug' => self::PERMALINK_PREFIX_AGENT_SKILL,
					'with_front' => false,
				],
				'query_var' => self::PERMALINK_PREFIX_AGENT_SKILL,
				'supports' => [ 'title', 'editor', 'excerpt', 'author', 'revisions', 'custom-fields' ],
				'template' => [
					[
						Skill_Post::BLOCK_NAME,
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
			Skill_Post::META_KEY_COMPATIBILITY,
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
				'show_in_menu' => false,
				'show_in_admin_bar' => true,
				'rewrite' => [
					'slug' => self::PERMALINK_PREFIX_AGENT_PLUGIN,
					'with_front' => false,
				],
				'query_var' => self::PERMALINK_PREFIX_AGENT_PLUGIN,
				'supports' => [ 'title', 'editor', 'excerpt', 'author', 'revisions' ],
				'template' => [
					[
						Agent_Plugin_Post::BLOCK_NAME_PLUGIN,
						[],
						[
							[ Agent_Plugin_Post::BLOCK_NAME_SKILL ],
							[ Agent_Plugin_Post::BLOCK_NAME_MCP ],
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
			? array_merge( [ Skill_Post::BLOCK_NAME ], Skill_Post::ALLOWED_BLOCKS )
			: [ Agent_Plugin_Post::BLOCK_NAME_PLUGIN, Agent_Plugin_Post::BLOCK_NAME_SKILL, Agent_Plugin_Post::BLOCK_NAME_MCP ];
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
