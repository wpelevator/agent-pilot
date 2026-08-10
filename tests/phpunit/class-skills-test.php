<?php

namespace WPElevator\Agent_Pilot_Tests;

use WPElevator\Agent_Pilot\Skill;
use WPElevator\Agent_Pilot\Skills;
use WPElevator\Agent_Pilot\Plugin;

class Skills_Test extends \WP_UnitTestCase {

	public function set_up() {
		parent::set_up();

		Plugin::action_register_post_type();
	}

	public function test_resolves_only_published_skills_in_name_order() {
		$skills = new Skills( Plugin::POST_TYPE );
		$this->create_skill( 'zebra-skill', 'publish' );
		$this->create_skill( 'alpha-skill', 'publish' );
		$this->create_skill( 'private-skill', 'private' );
		$public_skill_names = array_map(
			function ( Skill $skill ) {
				return $skill->get_name();
			},
			$skills->get_public_skills()
		);

		$this->assertSame( [ 'alpha-skill', 'zebra-skill' ], $public_skill_names, 'The collection should resolve published skills in name order.' );
	}

	public function test_public_lookup_hides_unpublished_skills() {
		$skills = new Skills( Plugin::POST_TYPE );
		$this->create_skill( 'public-skill', 'publish' );
		$this->create_skill( 'private-skill', 'private' );
		$this->create_skill( 'draft-skill', 'draft' );

		wp_set_current_user( 0 );

		$this->assertInstanceOf( Skill::class, $skills->get_public_skill( 'public-skill' ), 'Public lookups should resolve published skills.' );
		$this->assertNull( $skills->get_public_skill( 'private-skill' ), 'Public lookups should hide private skills.' );
		$this->assertNull( $skills->get_public_skill( 'draft-skill' ), 'Public lookups should hide draft skills.' );
		$this->assertNull( $skills->get_public_skill( 'missing-skill' ), 'Public lookups should return null for unknown skills.' );
	}

	private function create_skill( string $name, string $status ): int {
		return self::factory()->post->create(
			[
				'post_type' => Plugin::POST_TYPE,
				'post_name' => $name,
				'post_status' => $status,
			]
		);
	}
}
