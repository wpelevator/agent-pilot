<?php

namespace WPElevator\Agent_Pilot_Tests;

use WPElevator\Agent_Pilot\Zip_File;

class Zip_File_Test extends \WP_UnitTestCase {

	private string $file_path;

	public function set_up() {
		parent::set_up();

		$this->file_path = get_temp_dir() . '/agent-pilot-' . wp_generate_uuid4() . '.zip';
	}

	public function tear_down() {
		if ( is_file( $this->file_path ) ) {
			unlink( $this->file_path );
		}

		parent::tear_down();
	}

	public function test_reports_zip_archive_support() {
		$this->assertSame( class_exists( \ZipArchive::class ), Zip_File::is_supported(), 'ZIP support should reflect whether the ZipArchive extension is available.' );
	}

	public function test_creates_an_archive_from_file_contents() {
		if ( ! Zip_File::is_supported() ) {
			$this->markTestSkipped( 'ZIP creation requires the ZipArchive extension.' );
		}

		$zip_file = new Zip_File(
			$this->file_path,
			[
				'plugin.json' => "{\n}\n",
				'skills/example/SKILL.md' => "# Example\n",
			]
		);

		$this->assertSame( $this->file_path, $zip_file->get_file( 1700000000 ), 'ZIP generation should return the requested output path.' );

		$archive = new \ZipArchive();
		$this->assertTrue( $archive->open( $this->file_path ), 'The generated ZIP should be readable.' );
		$this->assertSame( "{\n}\n", $archive->getFromName( 'plugin.json' ), 'The generated ZIP should preserve root file contents.' );
		$this->assertSame( "# Example\n", $archive->getFromName( 'skills/example/SKILL.md' ), 'The generated ZIP should preserve nested file paths and contents.' );
		$archive->close();
	}

	public function test_overwrites_an_existing_archive() {
		if ( ! Zip_File::is_supported() ) {
			$this->markTestSkipped( 'ZIP creation requires the ZipArchive extension.' );
		}

		( new Zip_File( $this->file_path, [ 'plugin.json' => "first\n" ] ) )->get_file();
		( new Zip_File( $this->file_path, [ 'plugin.json' => "second\n" ] ) )->get_file();

		$archive = new \ZipArchive();
		$this->assertTrue( $archive->open( $this->file_path ), 'The overwritten ZIP should remain readable.' );
		$this->assertSame( "second\n", $archive->getFromName( 'plugin.json' ), 'ZIP generation should overwrite existing archives instead of applying an unknown cache policy.' );
		$archive->close();
	}
}
