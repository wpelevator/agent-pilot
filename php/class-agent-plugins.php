<?php

namespace WPElevator\Agent_Pilot;

use WP_Query;

class Agent_Plugins {

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
			fn( $post ): Agent_Plugin_Post => new Agent_Plugin_Post( is_numeric( $post ) ? get_post( $post ) : $post ),
			$query->posts
		);
	}

	/**
	 * Every package the current user is allowed to see.
	 *
	 * @see Skills::get_readable_skills()
	 */
	public function get_readable_plugins(): array {
		return array_values(
			array_filter(
				$this->query_plugins(),
				fn ( Agent_Plugin_Post $plugin ): bool => $plugin->can_read()
			)
		);
	}

	public function get_plugin_by_name( string $name ): ?Agent_Plugin_Post {
		$plugins = $this->query_plugins(
			[
				'name' => $name,
				'posts_per_page' => 1,
			]
		);

		return $plugins[0] ?? $this->get_draft_by_name( $name );
	}

	/**
	 * Resolve the name a package publishes itself under before it has a slug.
	 *
	 * @see Skills::get_draft_by_name()
	 */
	private function get_draft_by_name( string $name ): ?Agent_Plugin_Post {
		if ( ! preg_match( '/^' . Plugin::PERMALINK_PREFIX_AGENT_PLUGIN . '-(\d+)-draft$/', $name, $matches ) ) {
			return null;
		}

		$post = get_post( (int) $matches[1] );

		if ( ! $post || Plugin::POST_TYPE_AGENT_PLUGIN !== $post->post_type ) {
			return null;
		}

		$plugin = new Agent_Plugin_Post( $post );

		return $plugin->get_name() === $name ? $plugin : null;
	}

	/**
	 * The named package, when the current user is allowed to see it.
	 *
	 * @see Skills::get_readable_skill()
	 */
	public function get_readable_plugin( string $name ): ?Agent_Plugin_Post {
		$plugin = $this->get_plugin_by_name( $name );

		if ( $plugin && $plugin->can_read() ) {
			return $plugin;
		}

		return null;
	}

	public function get_public_plugin( string $name ): ?Agent_Plugin_Post {
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
