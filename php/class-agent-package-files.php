<?php

namespace WPElevator\Agent_Pilot;

/**
 * The files a package publishes, in the order it publishes them.
 *
 * The paths and the contents are one declaration rather than two lists that
 * drift apart, and since a `Package_File` resolves its contents only when read,
 * naming every file of every skill on a site renders no blocks and reads no
 * attachments. Asking for one file generates that one file.
 *
 * A path appears once: adding the same one twice replaces it in place, which is
 * what the generated archive does with two resources configured under the same
 * filename.
 */
class Agent_Package_Files {

	/**
	 * @var array<string, Package_File>
	 */
	private array $files = [];

	/**
	 * @param Package_File[] $files
	 */
	public function __construct( array $files = [] ) {
		foreach ( $files as $file ) {
			$this->add( $file );
		}
	}

	public function add( Package_File $file ): self {
		if ( '' !== $file->get_path() ) {
			$this->files[ $file->get_path() ] = $file;
		}

		return $this;
	}

	/**
	 * Add another package's files under a directory of this one.
	 */
	public function add_directory( string $directory, self $files ): self {
		foreach ( $files->files as $file ) {
			$this->add( $file->in_directory( $directory ) );
		}

		return $this;
	}

	public function get( string $path ): ?Package_File {
		return $this->files[ $path ] ?? null;
	}

	/**
	 * @return string[]
	 */
	public function get_paths(): array {
		return array_keys( $this->files );
	}

	public function get_contents( string $path ): ?string {
		$file = $this->get( $path );

		return $file ? $file->get_contents() : null;
	}

	/**
	 * Every file, with the contents of each one generated.
	 *
	 * @return array<string, string>
	 */
	public function to_array(): array {
		$contents = [];

		foreach ( $this->files as $path => $file ) {
			$contents[ $path ] = $file->get_contents();
		}

		return $contents;
	}
}
