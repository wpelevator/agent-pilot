<?php

namespace WPElevator\Agent_Pilot;

use WP_Query;

class Agent_Plugins {

	private Skills $skills;

	public function __construct( Skills $skills ) {
		$this->skills = $skills;
	}

	public function query_plugins( array $args = [] ): array {
		$query = new WP_Query(
			array_merge(
				[
					'post_type' => Plugin::POST_TYPE_AGENT_PLUGIN,
					'post_status' => 'any',
					'posts_per_page' => -1,
					'orderby' => 'name',
					'order' => 'ASC',
					'no_found_rows' => true,
				],
				$args
			)
		);

		return array_map(
			fn( $post ): Agent_Plugin => new Agent_Plugin(
				is_numeric( $post ) ? get_post( $post ) : $post,
				$this->skills
			),
			$query->posts
		);
	}

	public function get_public_plugin( string $name ): ?Agent_Plugin {
		$items = $this->query_plugins(
			[
				'name' => $name,
				'post_status' => 'publish',
				'posts_per_page' => 1,
			]
		);

		return $items[0] ?? null;
	}
}
