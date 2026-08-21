<?php

namespace WPElevator\Agent_Pilot;

use RuntimeException;
use ZipArchive;

class Zip_File {

	private string $file_path;
	private array $files;

	public function __construct( string $file_path, array $files ) {
		$this->file_path = $file_path;
		$this->files = $files;
	}

	public static function is_supported(): bool {
		return class_exists( ZipArchive::class );
	}

	public function get_file( ?int $last_modified = null ): string {
		if ( ! self::is_supported() ) {
			throw new RuntimeException( __( 'ZipArchive class is not available.', 'wpelevator-agent-pilot' ) );
		}

		$zip = new ZipArchive();

		if ( true !== $zip->open( $this->file_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			throw new RuntimeException( __( 'Failed to create ZIP file.', 'wpelevator-agent-pilot' ) );
		}

		foreach ( $this->files as $path => $contents ) {
			$zip->addFromString( $path, $contents );

			if ( $last_modified && method_exists( $zip, 'setMtimeName' ) ) {
				$zip->setMtimeName( $path, $last_modified );
			}
		}

		$zip->close();

		return $this->file_path;
	}
}
