<?php

namespace WPElevator\Agent_Pilot;

use WP_Error;

/**
 * Publishes the skills this site authors as WordPress Abilities, which the MCP
 * server then exposes as tools.
 *
 * Both abilities read what the discovery routes already serve — the generated
 * `SKILL.md` and the files packaged beside it — rather than the post content
 * behind it. A skill is authored as blocks, so its post content is an editor
 * document that no agent can act on, while the packaged files are the artifact
 * the specification defines and the one a client installs.
 *
 * Reading is therefore not the same operation as editing, and only reading is
 * defined here. Writing a skill means composing block markup, which is a larger
 * design question of its own.
 *
 * Neither ability knows the name it answers to. Each returns the arguments to
 * register one, and `Plugin` binds the names.
 */
class Skill_Abilities {

	private Skills $skills;

	private Discovery $discovery;

	public function __construct( Skills $skills, Discovery $discovery ) {
		$this->skills = $skills;
		$this->discovery = $discovery;
	}

	/**
	 * The ability that lists every skill the caller is allowed to see.
	 */
	public function get_list_args(): array {
		return [
			'label' => __( 'List Agent Skills', 'wpelevator-agent-pilot' ),
			'description' => __( 'List the Agent Skills authored on this site, with the description, status, packaged file paths and archive URL of each one. Published skills are listed for everyone; drafts and private skills only for a caller allowed to read them. Read the contents of one with the get-agent-skill ability, or install a skill from the archive its package_url points at, which carries its resources as well as its instructions.', 'wpelevator-agent-pilot' ),
			'category' => Plugin::ABILITY_CATEGORY,
			'output_schema' => [
				'type' => 'object',
				'properties' => [
					'skills' => [
						'type' => 'array',
						'items' => $this->get_skill_schema(),
					],
				],
			],
			/*
			 * Listing and reading skills is open to every caller, because a
			 * published skill is already public: the same content is served
			 * without authentication from its permalink and from the well-known
			 * discovery index. What is not public is decided per skill rather
			 * than per ability, so that check belongs to the lookup, where an
			 * unreadable skill is reported as missing. Refusing here instead
			 * would hide the public skills from everyone whose account happens
			 * not to reach one draft.
			 */
			'permission_callback' => '__return_true',
			'execute_callback' => [ $this, 'execute_list' ],
			'meta' => [
				'mcp' => [
					'public' => true,
					'type' => 'tool',
				],
				'annotations' => [
					'readonly' => true,
					'destructive' => false,
					'idempotent' => true,
				],
			],
		];
	}

	/**
	 * The ability that reads one skill by name.
	 */
	public function get_read_args(): array {
		return [
			'label' => __( 'Get Agent Skill', 'wpelevator-agent-pilot' ),
			'description' => __( 'Read one Agent Skill by name. Returns the same metadata as the listing along with the contents of its generated SKILL.md. To read a reference or a script instead, pass a path from the skill\'s own files list as the file argument. An asset is answered with its download URL rather than with its contents. To install the skill rather than read it, take the archive at package_url, which carries these files together.', 'wpelevator-agent-pilot' ),
			'category' => Plugin::ABILITY_CATEGORY,
			'input_schema' => [
				'type' => 'object',
				'properties' => [
					'name' => [
						'type' => 'string',
						'description' => __( 'The name of the skill, as reported by the listing.', 'wpelevator-agent-pilot' ),
					],
					'file' => [
						'type' => 'string',
						'description' => __( 'A path from the skill\'s files list, such as references/guide.md. Defaults to SKILL.md.', 'wpelevator-agent-pilot' ),
					],
				],
				'required' => [ 'name' ],
				'additionalProperties' => false,
			],
			'output_schema' => [
				'type' => 'object',
				'properties' => array_merge(
					$this->get_skill_schema()['properties'],
					[
						'file' => [
							'type' => 'string',
							'description' => __( 'The path of the returned file.', 'wpelevator-agent-pilot' ),
						],
						'content' => [
							'type' => 'string',
							'description' => __( 'The contents of the returned file.', 'wpelevator-agent-pilot' ),
						],
					]
				),
			],
			// Open to every caller for the reason `get_list_args()` explains.
			'permission_callback' => '__return_true',
			'execute_callback' => [ $this, 'execute_get' ],
			'meta' => [
				'mcp' => [
					'public' => true,
					'type' => 'tool',
				],
				'annotations' => [
					'readonly' => true,
					'destructive' => false,
					'idempotent' => true,
				],
			],
		];
	}

	/**
	 * @param mixed $input
	 */
	public function execute_list( $input = null ): array {
		return [
			'skills' => array_map(
				fn ( Skill_Post $skill ): array => $this->get_skill_data( $skill ),
				$this->skills->get_readable_skills()
			),
		];
	}

	/**
	 * @return array|WP_Error
	 */
	public function execute_get( array $input ) {
		$name = (string) ( $input['name'] ?? '' );
		$skill = $this->skills->get_readable_skill( $name );

		if ( ! $skill ) {
			return new WP_Error(
				'agent_pilot_skill_not_found',
				sprintf(
					/* translators: %s: the requested Agent Skill name. */
					__( 'No Agent Skill named %s is available to you.', 'wpelevator-agent-pilot' ),
					$name
				)
			);
		}

		$file = trim( (string) ( $input['file'] ?? '' ) );

		if ( '' === $file ) {
			$file = Skill_Post::FILE_SKILL_MD;
		}

		$content = $this->get_file_content( $skill, $file );

		if ( is_wp_error( $content ) ) {
			return $content;
		}

		return array_merge(
			$this->get_skill_data( $skill ),
			[
				'file' => $file,
				'content' => $content,
			]
		);
	}

	/**
	 * The contents of one packaged file.
	 *
	 * An asset is a file the author uploaded, published at a URL of its own, so
	 * it is answered with that URL rather than read into a JSON tool result.
	 *
	 * @return string|WP_Error
	 */
	private function get_file_content( Skill_Post $skill, string $path ) {
		$file = $skill->get_files()->get( $path );

		if ( ! $file ) {
			return new WP_Error(
				'agent_pilot_skill_file_not_found',
				sprintf(
					/* translators: 1: the Agent Skill name, 2: the requested file path. */
					__( 'The Agent Skill %1$s has no file named %2$s.', 'wpelevator-agent-pilot' ),
					$skill->get_name(),
					$path
				)
			);
		}

		if ( $file->is_asset() ) {
			return new WP_Error(
				'agent_pilot_skill_file_is_asset',
				sprintf(
					/* translators: 1: the requested file path, 2: the download URL. */
					__( '%1$s is an asset published from the media library rather than generated text. Download it from %2$s.', 'wpelevator-agent-pilot' ),
					$path,
					(string) $file->get_url()
				)
			);
		}

		return $file->get_contents();
	}

	private function get_skill_data( Skill_Post $skill ): array {
		$last_modified = $skill->get_last_modified();

		return [
			'id' => $skill->get_id(),
			'name' => $skill->get_name(),
			'title' => $skill->get_title(),
			'description' => $skill->get_description(),
			'compatibility' => $skill->get_compatibility(),
			'status' => $skill->get_post()->post_status,
			'is_public' => $skill->is_published(),
			'modified' => $last_modified ? gmdate( 'c', $last_modified ) : null,
			'permalink' => $skill->get_permalink(),
			'package_url' => $this->discovery->get_skill_zip_url( $skill ),
			'files' => $skill->get_files()->get_paths(),
		];
	}

	private function get_skill_schema(): array {
		return [
			'type' => 'object',
			'properties' => [
				'id' => [
					'type' => 'integer',
					'description' => __( 'The WordPress post ID of the skill.', 'wpelevator-agent-pilot' ),
				],
				'name' => [
					'type' => 'string',
					'description' => __( 'The skill name, which is the post slug.', 'wpelevator-agent-pilot' ),
				],
				'title' => [ 'type' => 'string' ],
				'description' => [ 'type' => 'string' ],
				'compatibility' => [ 'type' => 'string' ],
				'status' => [
					'type' => 'string',
					'description' => __( 'The WordPress post status. Only a published skill is publicly available.', 'wpelevator-agent-pilot' ),
				],
				'is_public' => [ 'type' => 'boolean' ],
				'modified' => [
					'type' => [ 'string', 'null' ],
					'description' => __( 'When the skill last changed, in ISO 8601 UTC.', 'wpelevator-agent-pilot' ),
				],
				'permalink' => [ 'type' => 'string' ],
				'package_url' => [
					'type' => 'string',
					'description' => __( 'Where the skill archive is served. It is the whole skill, carrying SKILL.md together with the references, scripts and assets it lists, so it is what a client installs rather than the Markdown alone.', 'wpelevator-agent-pilot' ),
				],
				'files' => [
					'type' => 'array',
					'items' => [ 'type' => 'string' ],
					'description' => __( 'The paths this skill packages, which are the values the file argument accepts.', 'wpelevator-agent-pilot' ),
				],
			],
		];
	}
}
