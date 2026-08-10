<?php

namespace WPElevator\Agent_Pilot_Tests;

use WPElevator\Agent_Pilot\Discovery;
use WPElevator\Agent_Pilot\Plugin;
use WPElevator\Agent_Pilot\Request;
use WPElevator\Agent_Pilot\Response_Emitter;
use WPElevator\Agent_Pilot\Skill;
use WPElevator\Agent_Pilot\Skills;

class Discovery_Test extends \WP_UnitTestCase {

	private Discovery $discovery;

	private Skills $skills;

	public function set_up() {
		parent::set_up();

		Plugin::action_register_post_type();

		$this->skills = new Skills( Plugin::POST_TYPE );
		$this->discovery = new Discovery( $this->skills, new Response_Emitter( new Request() ) );
	}

	public function test_registers_the_well_known_index_rewrite_rule() {
		global $wp_rewrite;

		$this->discovery->action_add_rewrite_rules();

		$this->assertSame(
			'index.php?' . Discovery::QUERY_INDEX . '=1',
			$wp_rewrite->extra_rules_top['^\.well-known/agent-skills/index\.json$'],
			'The discovery index should be routed outside wp-json.'
		);
	}

	public function test_skill_format_route_resolves_the_skill_and_the_requested_artifact() {
		$this->discovery->action_add_rewrite_rules();

		$pattern = $this->get_skill_format_rewrite_pattern();

		$this->assertSame(
			sprintf(
				'index.php?post_type=%s&%s=$matches[1]&%s=$matches[2]',
				Plugin::POST_TYPE,
				Plugin::PERMALINK_PREFIX,
				Discovery::SKILL_FORMAT
			),
			$GLOBALS['wp_rewrite']->extra_rules_top[ $pattern ],
			'The artifact route should resolve the skill through its public query variable and pass the requested format along.'
		);

		foreach ( [ Discovery::SKILL_FORMAT_MD, Discovery::SKILL_FORMAT_ZIP ] as $format ) {
			$this->assertSame(
				1,
				preg_match( '#' . $pattern . '#', sprintf( 'agent-skill/example-skill/%s', $format ) ),
				sprintf( 'The %s artifact path should match the skill format route.', $format )
			);
		}

		$this->assertSame(
			0,
			preg_match( '#' . $pattern . '#', 'agent-skill/example-skill/references/guide.md' ),
			'The artifact route should not claim nested resource paths.'
		);
	}

	public function test_registers_its_query_variables() {
		$this->assertSame(
			[ 'existing', Discovery::QUERY_INDEX, Discovery::SKILL_FORMAT ],
			$this->discovery->filter_query_vars( [ 'existing' ] ),
			'Discovery should preserve existing query variables and add the index and format selectors.'
		);
	}

	public function test_advertises_the_index_through_the_link_header() {
		$link = sprintf( '<%s>; rel="agent-skills"', $this->discovery->get_index_url() );

		$this->assertSame(
			$link,
			$this->discovery->filter_wp_headers( [] )['Link'],
			'Every response should advertise the discovery index through a Link header.'
		);
		$this->assertSame(
			'<https://example.org/other>; rel="alternate", ' . $link,
			$this->discovery->filter_wp_headers( [ 'Link' => '<https://example.org/other>; rel="alternate"' ] )['Link'],
			'The discovery Link value should be appended to existing Link headers.'
		);
	}

	public function test_resolves_the_absolute_index_url() {
		$this->assertSame( home_url( Discovery::INDEX_PATH ), $this->discovery->get_index_url(), 'Discovery should own the canonical absolute URL for its well-known index.' );
	}

	public function test_artifact_urls_use_query_arguments_on_plain_permalinks() {
		$skill = $this->create_skill( 'alpha-skill', 'Alpha description.', 'publish' );
		$permalink = $skill->get_permalink();

		$this->assertStringContainsString( '?', $permalink, 'Plain permalinks are expected to carry a query string in this test.' );
		$this->assertSame(
			add_query_arg( [ Discovery::SKILL_FORMAT => Discovery::SKILL_FORMAT_MD ], $permalink ),
			$this->discovery->get_skill_md_url( $skill ),
			'Without pretty permalinks the artifact format should travel as a query argument.'
		);
		$this->assertSame(
			add_query_arg( [ Discovery::SKILL_FORMAT => Discovery::SKILL_FORMAT_ZIP ], $permalink ),
			$this->discovery->get_skill_zip_url( $skill ),
			'The ZIP artifact should use the same query argument as the Markdown artifact.'
		);
	}

	public function test_artifact_urls_extend_the_permalink_path_on_pretty_permalinks() {
		$this->set_permalink_structure( '/%postname%/' );

		Plugin::action_register_post_type(); // Post type permastructs are only added once pretty permalinks are enabled.

		$skill = $this->create_skill( 'alpha-skill', 'Alpha description.', 'publish' );

		$this->assertStringNotContainsString( '?', $skill->get_permalink(), 'Pretty permalinks are expected to be free of a query string in this test.' );

		$this->assertSame(
			rtrim( $skill->get_permalink(), '/' ) . '/' . Discovery::SKILL_FORMAT_MD,
			$this->discovery->get_skill_md_url( $skill ),
			'With pretty permalinks the artifact should be a suffix of the skill permalink.'
		);
		$this->assertSame(
			rtrim( $skill->get_permalink(), '/' ) . '/' . Discovery::SKILL_FORMAT_ZIP,
			$this->discovery->get_skill_zip_url( $skill ),
			'The ZIP artifact should extend the skill permalink the same way.'
		);
	}

	public function test_index_lists_published_skills_as_archives_in_name_order() {
		$this->create_skill( 'zebra-skill', 'Zebra description.', 'publish' );
		$this->create_skill( 'alpha-skill', 'Alpha description.', 'publish' );
		$this->create_skill( 'private-skill', 'Private description.', 'private' );
		$this->create_skill( 'draft-skill', 'Draft description.', 'draft' );

		$index = $this->discovery->get_index_data();

		$this->assertSame( Discovery::SCHEMA, $index['$schema'], 'The index should use the exact discovery schema URI.' );
		$this->assertSame( [ 'alpha-skill', 'zebra-skill' ], wp_list_pluck( $index['skills'], 'name' ), 'Index entries should be sorted and exclude non-public skills.' );
		$this->assertSame( [ 'archive', 'archive' ], wp_list_pluck( $index['skills'], 'type' ), 'Skills are published as downloadable archives.' );
		$this->assertSame( [ 'Alpha description.', 'Zebra description.' ], wp_list_pluck( $index['skills'], 'description' ), 'Index entries should carry the skill description.' );
		$this->assertSame( [ Discovery::SKILL_FILE ], $index['skills'][0]['files'], 'Index entries should keep the SKILL.md placeholder required by the v0.1 schema.' );
	}

	public function test_index_entries_link_to_the_skill_archive_with_its_digest() {
		$skill = $this->create_skill( 'alpha-skill', 'Alpha description.', 'publish' );

		$index = $this->discovery->get_index_data();

		$this->assertSame(
			$this->discovery->get_skill_zip_url( $skill ),
			$index['skills'][0]['url'],
			'Index entries should point at the downloadable skill archive.'
		);
		$this->assertSame(
			'sha256:' . hash_file( 'sha256', $this->discovery->get_skill_zip_file( $skill ) ),
			$index['skills'][0]['digest'],
			'Index digests should hash the exact archive bytes that are served.'
		);
	}

	public function test_skill_archive_contains_the_generated_markdown_under_the_skill_directory() {
		$skill = $this->create_skill( 'alpha-skill', 'Alpha description.', 'publish' );

		$archive = new \ZipArchive();

		$this->assertTrue( $archive->open( $this->discovery->get_skill_zip_file( $skill ) ), 'The generated skill archive should be a readable ZIP file.' );
		$this->assertSame( 1, $archive->count(), 'The archive should currently package only the generated SKILL.md.' );
		$this->assertSame(
			$skill->get_as_markdown(),
			$archive->getFromName( sprintf( '%s/%s', $skill->get_name(), Discovery::SKILL_FILE ) ),
			'The archive should place the generated SKILL.md inside a directory named after the skill.'
		);

		$archive->close();
	}

	private function create_skill( string $name, string $description, string $status ): Skill {
		$post_id = self::factory()->post->create(
			[
				'post_type' => Plugin::POST_TYPE,
				'post_name' => $name,
				'post_title' => ucwords( str_replace( '-', ' ', $name ) ),
				'post_excerpt' => $description,
				'post_content' => '<!-- wp:paragraph --><p>Follow these instructions.</p><!-- /wp:paragraph -->',
				'post_status' => $status,
			]
		);

		return Skill::from_post_id( $post_id );
	}

	private function get_skill_format_rewrite_pattern(): string {
		global $wp_rewrite;

		foreach ( $wp_rewrite->extra_rules_top as $pattern => $query ) {
			if ( false !== strpos( $query, Discovery::SKILL_FORMAT ) ) {
				return $pattern;
			}
		}

		$this->fail( 'Discovery should register a rewrite rule for the skill artifact formats.' );
	}
}
