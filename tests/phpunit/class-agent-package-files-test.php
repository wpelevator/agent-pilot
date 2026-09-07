<?php

namespace WPElevator\Agent_Pilot_Tests;

use WPElevator\Agent_Pilot\Agent_Package_Files;
use WPElevator\Agent_Pilot\Package_File;

class Agent_Package_Files_Test extends \WP_UnitTestCase {

	public function test_paths_are_listed_without_generating_any_contents() {
		$generated = 0;
		$files = new Agent_Package_Files(
			[
				Package_File::from_callback(
					'SKILL.md',
					function () use ( &$generated ): string {
						++$generated;
						return 'instructions';
					}
				),
			]
		);

		$paths = $files->get_paths();

		$this->assertSame( [ 'SKILL.md' ], $paths, 'A package should be able to name its files.' );
		$this->assertSame( 0, $generated, 'Naming the files must not generate them, since a listing names the files of every package on the site.' );
		$this->assertSame( 'instructions', $files->get_contents( 'SKILL.md' ), 'Reading a file should generate it.' );
		$this->assertSame( 1, $generated, 'Reading one file should generate that one file.' );
	}

	public function test_generating_one_file_leaves_the_others_alone() {
		$generated = [];
		$files = new Agent_Package_Files();
		foreach ( [ 'references/one.md', 'references/two.md' ] as $path ) {
			$files->add(
				Package_File::from_callback(
					$path,
					function () use ( &$generated, $path ): string {
						$generated[] = $path;
						return $path;
					}
				)
			);
		}

		$files->get_contents( 'references/two.md' );

		$this->assertSame( [ 'references/two.md' ], $generated, 'Reading one reference should not render every other file of the package.' );
	}

	public function test_contents_that_name_a_php_function_are_published_as_written() {
		$files = new Agent_Package_Files( [ Package_File::from_contents( 'scripts/build.sh', 'trim' ) ] );

		$this->assertSame( 'trim', $files->get_contents( 'scripts/build.sh' ), 'Contents are declared as contents, so a file that happens to name a PHP function is published rather than called.' );
	}

	public function test_a_method_pair_is_deferred_the_way_a_closure_is() {
		$asset = new Agent_Package_Files( [ Package_File::from_contents( 'notes.txt', 'uploaded' ) ] );
		$files = new Agent_Package_Files(
			[
				Package_File::from_asset( 'assets/notes.txt', [ $asset, 'to_array' ], 'https://example.com/notes.txt' ),
			]
		);

		$this->assertSame( [ 'assets/notes.txt' ], $files->get_paths(), 'Any callable should be accepted, not only a closure.' );
	}

	public function test_an_asset_keeps_its_download_url_when_bundled() {
		$skill = new Agent_Package_Files(
			[
				Package_File::from_contents( 'SKILL.md', 'instructions' ),
				Package_File::from_asset( 'assets/diagram.png', 'uploaded', 'https://example.com/diagram.png' ),
			]
		);
		$plugin = ( new Agent_Package_Files() )->add_directory( 'skills/example', $skill );
		$asset = $plugin->get( 'skills/example/assets/diagram.png' );

		$this->assertTrue( $asset->is_asset(), 'Bundling a package should carry which of its files the author supplied.' );
		$this->assertSame( 'https://example.com/diagram.png', $asset->get_url(), 'A bundled asset should still say where it can be downloaded from.' );
		$this->assertSame( 'uploaded', $asset->get_contents(), 'An asset is still published into the archive like any other file.' );
		$this->assertFalse( $plugin->get( 'skills/example/SKILL.md' )->is_asset(), 'A generated file should not become an asset by being bundled next to one.' );
	}

	public function test_a_nested_package_keeps_its_contents_ungenerated() {
		$generated = 0;
		$skill = new Agent_Package_Files(
			[
				Package_File::from_callback(
					'SKILL.md',
					function () use ( &$generated ): string {
						++$generated;
						return 'instructions';
					}
				),
			]
		);
		$plugin = ( new Agent_Package_Files( [ Package_File::from_contents( 'plugin.json', '{}' ) ] ) )
			->add_directory( 'skills/example', $skill );

		$paths = $plugin->get_paths();

		$this->assertSame( [ 'plugin.json', 'skills/example/SKILL.md' ], $paths, 'A nested package should be published under its own directory.' );
		$this->assertSame( 0, $generated, 'Nesting a package inside another should cost nothing until its files are read.' );
		$this->assertSame(
			[
				'plugin.json' => '{}',
				'skills/example/SKILL.md' => 'instructions',
			],
			$plugin->to_array(),
			'Asking for every file should generate every file.'
		);
		$this->assertSame( 'SKILL.md', $skill->get_paths()[0], 'Bundling a package should not move the files of the package that was bundled.' );
	}

	public function test_a_file_added_twice_keeps_its_place_and_takes_the_later_one() {
		$files = new Agent_Package_Files(
			[
				Package_File::from_contents( 'a.md', 'first' ),
				Package_File::from_contents( 'b.md', 'second' ),
			]
		);

		$files->add( Package_File::from_contents( 'a.md', 'replaced' ) );

		$this->assertSame(
			[
				'a.md' => 'replaced',
				'b.md' => 'second',
			],
			$files->to_array(),
			'Two resources configured with the same filename should collapse the way the generated archive collapses them.'
		);
	}
}
