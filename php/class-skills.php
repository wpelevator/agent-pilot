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
			fn( $post ): Skill => is_numeric( $post ) ? Skill::from_post_id( (int) $post ) : new Skill( $post ),
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

	public function get_skill_by_name( string $name ): ?Skill {
		$skills = $this->query_skills(
			[
				'name' => $name,
				'posts_per_page' => 1,
			]
		);

		return $skills[0] ?? null;
	}

	public function get_public_skill( string $name ): ?Skill {
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
