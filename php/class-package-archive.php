<?php

namespace WPElevator\Agent_Pilot;

use RuntimeException;

/**
 * The archive a package is distributed as.
 *
 * Building one generates every file the package publishes, so the result is
 * kept in the temporary directory under the package's own hash. That hash
 * changes whenever the published files would, which is what makes a cached
 * archive safe to serve again rather than a guess about staleness.
 */
class Package_Archive {

	/**
	 * Bump when the generated archive changes shape, so that files cached by an
	 * earlier version are passed over instead of served.
	 */
	private const CACHE_VERSION = 'v1';

	public function is_supported(): bool {
		return Zip_File::is_supported();
	}

	/**
	 * The archive on disk, generating it if it is not cached already.
	 *
	 * @throws RuntimeException If archives cannot be generated on this site.
	 */
	public function get_file( Agent_Package $package ): string {
		if ( ! $this->is_supported() ) {
			throw new RuntimeException( __( 'ZipArchive class is not available. Please ensure the PHP zip extension is installed and enabled.', 'wpelevator-agent-pilot' ) );
		}

		$file = sprintf(
			'%s/agent-package-%s-%s.zip',
			untrailingslashit( get_temp_dir() ),
			self::CACHE_VERSION,
			$package->get_hash()
		);

		if ( is_readable( $file ) ) {
			return $file;
		}

		$zip = new Zip_File( $file, $package->get_files()->to_array() );

		return $zip->get_file( $package->get_last_modified() );
	}

	/**
	 * @throws RuntimeException If archives cannot be generated on this site.
	 */
	public function get_contents( Agent_Package $package ): string {
		return (string) file_get_contents( $this->get_file( $package ) );
	}

	public function get_filename( Agent_Package $package ): string {
		return sprintf( '%s.zip', $package->get_name() );
	}
}
