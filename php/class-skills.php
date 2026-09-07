<?php

namespace WPElevator\Agent_Pilot;

use WP_Query;

class Skills {

	private string $post_type;

	public function __construct( string $post_type ) {
		$this->post_type = $post_type;
	}

	public function get_post_type(): string {
		return $this->post_type;
	}

	public function query_skills( ?array $query_args = [] ): array {
		$default_args = [
			'post_type' => $this->post_type,
			'post_status' => 'any',
			'posts_per_page' => -1,
			'fields' => 'ids',
			'orderby' => 'name',
			'order' => 'ASC',
			'no_found_rows' => true,
			'ignore_sticky_posts' => true,
		];

		$query = new WP_Query( array_merge( $default_args, $query_args ?? [] ) );

		return array_map(
			fn( $post ): Skill_Post => is_numeric( $post ) ? Skill_Post::from_post_id( (int) $post ) : new Skill_Post( $post ),
			$query->posts
		);
	}

	public function get_public_skills(): array {
		return $this->query_skills(
			[
				'post_status' => 'publish',
			]
		);
	}

	/**
	 * Every skill the current user is allowed to see.
	 *
	 * `WP_Query` returns drafts and private posts to everyone unless it is asked
	 * for a permission check, so the visibility rule is applied here rather than
	 * left to the caller.
	 */
	public function get_readable_skills(): array {
		return array_values(
			array_filter(
				$this->query_skills(),
				fn ( Skill_Post $skill ): bool => $skill->can_read()
			)
		);
	}

	public function get_skill_by_name( string $name ): ?Skill_Post {
		$skills = $this->query_skills(
			[
				'name' => $name,
				'posts_per_page' => 1,
			]
		);

		return $skills[0] ?? $this->get_draft_by_name( $name );
	}

	/**
	 * Resolve the name a skill publishes itself under before it has a slug.
	 *
	 * That name carries the post ID it was generated from, so the ID is read
	 * back out of it and the skill itself confirms that this is the name it
	 * publishes under, rather than this having its own idea of how one is built.
	 */
	private function get_draft_by_name( string $name ): ?Skill_Post {
		if ( ! preg_match( '/^' . Plugin::PERMALINK_PREFIX_AGENT_SKILL . '-(\d+)-draft$/', $name, $matches ) ) {
			return null;
		}

		$skill = Skill_Post::from_post_id( (int) $matches[1] );

		return $skill && $skill->get_name() === $name ? $skill : null;
	}

	/**
	 * The named skill, when the current user is allowed to see it.
	 *
	 * A skill the caller may not read is indistinguishable from one that does
	 * not exist, so that the names of unpublished skills cannot be discovered by
	 * comparing one refusal against another.
	 */
	public function get_readable_skill( string $name ): ?Skill_Post {
		$skill = $this->get_skill_by_name( $name );

		if ( $skill && $skill->can_read() ) {
			return $skill;
		}

		return null;
	}

	public function get_public_skill( string $name ): ?Skill_Post {
		$skills = $this->query_skills(
			[
				'name' => $name,
				'post_status' => 'publish',
				'posts_per_page' => 1,
			]
		);

		return $skills[0] ?? null;
	}
}
