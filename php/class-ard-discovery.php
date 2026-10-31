<?php

namespace WPElevator\Agent_Pilot;

/**
 * Publishes the skills and Agent Plugins of this site as an Agentic Resource
 * Discovery manifest.
 *
 * The manifest is built from the same published packages as the Agent Skills
 * discovery index, so the two never disagree about what the site offers. It
 * points at the archives that index serves rather than describing the
 * packages a second time.
 *
 * @see https://agenticresourcediscovery.org/spec/
 */
class Ard_Discovery {

	public const MANIFEST_PATH = '/.well-known/ard.json';

	public const QUERY_MANIFEST = 'agent_pilot_ard';

	public const TYPE_SKILL = 'application/agent-skills+zip';

	public const TYPE_PLUGIN = 'application/agent-plugins+zip';

	public const NAMESPACE_SKILL = 'skill';

	public const NAMESPACE_PLUGIN = 'plugin';

	/**
	 * What the specification allows in the publisher segment of an identifier.
	 */
	private const PUBLISHER_PATTERN = '/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/';

	private Skills $skills;

	private Agent_Plugins $plugins;

	private Discovery $discovery;

	private Agent_Plugin_Discovery $plugin_discovery;

	private Response_Emitter $emitter;

	public function __construct( Skills $skills, Agent_Plugins $plugins, Discovery $discovery, Agent_Plugin_Discovery $plugin_discovery, Response_Emitter $emitter ) {
		$this->skills = $skills;
		$this->plugins = $plugins;
		$this->discovery = $discovery;
		$this->plugin_discovery = $plugin_discovery;
		$this->emitter = $emitter;
	}

	public function init(): void {
		add_action( 'init', [ $this, 'action_add_rewrite_rules' ] );
		add_filter( 'query_vars', [ $this, 'filter_query_vars' ] );
		add_action( 'wp_head', [ $this, 'action_print_link_tag' ] );
		add_action( 'template_redirect', [ $this, 'action_serve_manifest' ], 0 );
	}

	public function get_manifest_url(): string {
		return home_url( self::MANIFEST_PATH );
	}

	public function action_add_rewrite_rules(): void {
		add_rewrite_rule(
			'^\.well-known/ard\.json$',
			'index.php?' . self::QUERY_MANIFEST . '=1',
			'top'
		);
	}

	public function filter_query_vars( array $query_vars ): array {
		$query_vars[] = self::QUERY_MANIFEST;

		return $query_vars;
	}

	public function action_print_link_tag(): void {
		printf( '<link rel="ard" href="%s">' . "\n", esc_url( $this->get_manifest_url() ) );
	}

	/**
	 * The domain the identifiers are anchored to, or null when the site's host
	 * is not one the specification can express, like a name with an underscore.
	 * A port is not part of the host, so it never reaches the identifier.
	 */
	public function get_publisher(): ?string {
		$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );

		return 1 === preg_match( self::PUBLISHER_PATTERN, $host ) ? $host : null;
	}

	public function get_manifest(): array {
		$publisher = $this->get_publisher();
		$entries = [];

		if ( null !== $publisher ) {
			$entries = array_merge(
				$this->get_skill_entries( $publisher ),
				$this->get_plugin_entries( $publisher )
			);
		}

		/**
		 * Filters the entries of the Agentic Resource Discovery manifest.
		 *
		 * @param array[] $entries Entries, each with at least `identifier`, `displayName`, `type` and `url`.
		 */
		$entries = apply_filters( 'agent_pilot__ard_entries', $entries );

		return [
			'entries' => array_values( (array) $entries ),
		];
	}

	private function get_skill_entries( string $publisher ): array {
		return array_map(
			fn ( Skill_Post $skill ): array => $this->get_entry(
				$skill,
				$publisher,
				self::NAMESPACE_SKILL,
				self::TYPE_SKILL,
				$this->discovery->get_skill_zip_url( $skill )
			),
			$this->skills->get_public_skills()
		);
	}

	private function get_plugin_entries( string $publisher ): array {
		$plugins = array_filter(
			$this->plugins->query_plugins( [ 'post_status' => 'publish' ] ),
			fn ( Agent_Plugin_Post $plugin ): bool => $plugin->is_valid( true )
		);

		return array_map(
			fn ( Agent_Plugin_Post $plugin ): array => $this->get_entry(
				$plugin,
				$publisher,
				self::NAMESPACE_PLUGIN,
				self::TYPE_PLUGIN,
				$this->plugin_discovery->get_plugin_zip_url( $plugin )
			),
			array_values( $plugins )
		);
	}

	private function get_entry( Post $package, string $publisher, string $segment, string $type, string $url ): array {
		$title = $package->get_title();
		$description = $package->get_description();
		$modified = $package->get_last_modified();

		return array_filter(
			[
				'identifier' => sprintf( 'urn:air:%s:%s:%s', $publisher, $segment, $package->get_name() ),
				'displayName' => '' !== $title ? $title : $package->get_name(),
				'type' => $type,
				'url' => $url,
				'description' => $description,
				'updatedAt' => $modified ? gmdate( 'c', $modified ) : '',
			],
			fn ( string $value ): bool => '' !== $value
		);
	}

	public function action_serve_manifest(): void {
		if ( get_query_var( self::QUERY_MANIFEST ) ) {
			$this->emitter->send( Response::as_json( 200, $this->get_manifest() ) );
		}
	}
}
