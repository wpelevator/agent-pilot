<?php

namespace WPElevator\Agent_Pilot;

use RuntimeException;
use WP_Post;

class Discovery {
	public const SKILL_FORMAT = 'agent_pilot_skill_format';

	public const SKILL_FILE = 'SKILL.md';

	public const SKILL_FORMAT_MD = 'skill.md';

	public const SKILL_FORMAT_ZIP = 'skill.zip';

	public const INDEX_PATH = '/.well-known/agent-skills/index.json';

	public const SCHEMA = 'https://schemas.agentskills.io/discovery/0.2.0/schema.json';

	public const QUERY_INDEX = 'agent_pilot_index';

	private Skills $skills;

	private Response_Emitter $response_emitter;

	private Cache_Transient $cache;

	public function __construct( Skills $skills, Response_Emitter $response_emitter ) {
		$this->skills = $skills;
		$this->response_emitter = $response_emitter;

		$this->cache = new Cache_Transient( 'agent-pilot' );
	}

	public function init() {
		add_action( 'init', [ $this, 'action_add_rewrite_rules' ] );
		add_filter( 'query_vars', [ $this, 'filter_query_vars' ] );
		add_filter( 'wp_headers', [ $this, 'filter_wp_headers' ] );

		add_action( 'template_redirect', [ $this, 'action_serve_discovery' ], 0 );
		add_action( 'template_redirect', [ $this, 'action_serve_file' ], 0 );
	}

	public function get_index_url(): string {
		return home_url( self::INDEX_PATH ); // On multisite sub-directory this will be under sub-directory which appears to be OK by most skills clients.
	}

	public function action_add_rewrite_rules() {
		add_rewrite_rule(
			'^\.well-known/agent-skills/index\.json$',
			'index.php?' . self::QUERY_INDEX . '=1',
			'top'
		);

		add_rewrite_rule(
			sprintf(
				'%s/([^/]+)/([A-Za-z\.\-]+)$', // TODO: Consider reading this from existing post rewrite rules instead.
				Plugin::PERMALINK_PREFIX_AGENT_SKILL,
			),
			sprintf(
				'index.php?post_type=%s&%s=$matches[1]&%s=$matches[2]',
				Plugin::POST_TYPE_AGENT_SKILL,
				Plugin::PERMALINK_PREFIX_AGENT_SKILL,
				self::SKILL_FORMAT
			),
			'top'
		);
	}

	public function filter_query_vars( array $query_vars ): array {
		$query_vars[] = self::QUERY_INDEX;
		$query_vars[] = self::SKILL_FORMAT;

		return $query_vars;
	}

	public function filter_wp_headers( array $headers ): array {
		$headers['Link'] = $this->append_link_header( $headers['Link'] ?? '' );

		return $headers;
	}

	public function get_index_data(): array {
		$skills = [];

		foreach ( $this->skills->get_public_skills() as $skill ) {
			try {
				$zip_file = $this->get_skill_zip_file( $skill );

				$skills[] = [
					'name' => $skill->get_name(),
					'type' => 'archive', // TODO: Consider returning skill-md if script has no linked resources.
					'description' => $skill->get_description(),
					'url' => $this->get_skill_zip_url( $skill ),
					'digest' => 'sha256:' . hash_file( 'sha256', $zip_file ),
					'files' => [
						/**
						 * For compatability with v0.1.0 of the discovery schema which requires
						 * files to not be empty. Note that we don't actually support routes like
						 * .well-known/agent-skills/{name}/SKILL.md, so this is just a placeholder
						 * for now.
						 */
						'SKILL.md',
					],
				];
			} catch ( RuntimeException $e ) {
				// TODO: Gate the error messages to logged-in users.
				error_log( sprintf( 'Error generating ZIP for skill ID %d (%s): %s', $skill->get_id(), $skill->get_name(), $e->getMessage() ) );
			}
		}

		return [
			'$schema' => self::SCHEMA,
			'skills' => $skills,
		];
	}

	private function append_link_header( ?string $existing_link ): string {
		$link = sprintf(
			'<%s>; rel="agent-skills"',
			$this->get_index_url()
		);

		return empty( $existing_link )
			? $link
			: $existing_link . ', ' . $link;
	}

	private function get_skill_format_url( Skill $skill, string $format ): string {
		$permalink = $skill->get_permalink();

		if ( false !== strpos( $permalink, '?' ) ) { // TODO: replace with proper pretty permalink check.
			return add_query_arg(
				[ self::SKILL_FORMAT => $format ],
				$permalink
			);
		}

		return sprintf(
			'%s/%s',
			rtrim( $permalink, '/' ),
			ltrim( $format, '/' )
		);
	}

	public function get_skill_md_url( Skill $skill ): string {
		return $this->get_skill_format_url( $skill, self::SKILL_FORMAT_MD );
	}

	public function get_skill_zip_url( Skill $skill ): string {
		return $this->get_skill_format_url( $skill, self::SKILL_FORMAT_ZIP );
	}

	public function get_skill_zip_file( Skill $skill ): string {
		if ( ! Zip_File::is_supported() ) {
			throw new RuntimeException( __( 'ZipArchive class is not available. Please ensure the PHP zip extension is installed and enabled.', 'wpelevator-agent-pilot' ) );
		}

		$zip_cache_file = sprintf(
			'%s/agent-skill-v3-%s.zip', // Bump the version number when the ZIP generation logic changes.
			get_temp_dir(),
			$skill->get_hash()
		);

		if ( is_readable( $zip_cache_file ) ) {
			return $zip_cache_file;
		}

		$zip_file = new Zip_File( $zip_cache_file, $skill->get_files() );

		return $zip_file->get_file( $skill->get_last_modified() );
	}

	public function action_serve_discovery() {
		if ( get_query_var( self::QUERY_INDEX ) ) {
			$this->response_emitter->send( Response::as_json( 200, $this->get_index_data() ) );
		}
	}

	public function action_serve_file(): void {
		$skill_format = get_query_var( self::SKILL_FORMAT );

		if ( empty( $skill_format ) || ! is_string( $skill_format ) ) {
			return;
		}

		$skill_format = strtolower( $skill_format ); // Normalize to lowercase for easier matching.
		$queried_object = get_queried_object();
		$name = (string) get_query_var( Plugin::PERMALINK_PREFIX_AGENT_SKILL ); // TODO: this will be empty if the post type is not public.

		if ( $queried_object instanceof WP_Post && Plugin::POST_TYPE_AGENT_SKILL === $queried_object->post_type ) {
			$skill = new Skill( $queried_object );
		} elseif ( ! empty( $name ) ) {
			$skill = $this->skills->get_public_skill( $name );
		}

		if ( isset( $skill ) && ( $skill->is_published() || current_user_can( 'read_post', $skill->get_id() ) ) ) {
			$headers = [];

			if ( ! $skill->is_published() ) {
				$headers = wp_get_nocache_headers();
			}

			if ( self::SKILL_FORMAT_MD === $skill_format ) {
				$skill_markdown = $this->cache->get(
					sprintf( 'skill-md-v2-%d-%s', $skill->get_id(), $skill->get_hash() ),
					fn (): string => $skill->get_as_markdown(),
					HOUR_IN_SECONDS
				);

				$response = Response::as_markdown( 200, $skill_markdown, $headers );
			} elseif ( self::SKILL_FORMAT_ZIP === $skill_format ) {
				try {
					$response = Response::as_file(
						200,
						$this->get_skill_zip_file( $skill ),
						sprintf( '%s.zip', $skill->get_name() ),
						$headers
					);
				} catch ( RuntimeException $e ) {
					// TODO: Show the error messages to logged-in users.
					error_log( sprintf( 'Error generating ZIP for skill ID %d (%s): %s', $skill->get_id(), $skill->get_name(), $e->getMessage() ) );
				}
			}

			if ( isset( $response ) ) {
				$this->response_emitter->send( $response );
			}
		}
	}
}
