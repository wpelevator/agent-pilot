<?php

namespace WPElevator\Agent_Pilot;

/**
 * One file a package publishes: where it sits, and how to get its contents.
 *
 * Contents are resolved when the file is read rather than when it is declared,
 * which is what lets a listing name every file of every package on a site
 * without generating any of them. How they are resolved is the named
 * constructor's business — a string already in hand, or a callable — and a
 * caller reads the file the same way either way.
 *
 * A file also carries its media type, which is what tells a caller whether the
 * contents can be sent as text or have to be encoded. It is derived from the
 * path unless the file knows better, as an uploaded one does. A modification
 * time to stamp an archive entry with would belong here too, rather than in
 * another map keyed by path beside this one.
 */
class Package_File {

	private string $path;

	/**
	 * @var string|callable
	 */
	private $contents;

	/**
	 * Whether the author supplied this file rather than the package generating
	 * it, and where it is published in its own right.
	 */
	private bool $is_asset = false;

	private ?string $url = null;

	private ?string $mime_type = null;

	/**
	 * The media types of the files a package generates, by extension.
	 *
	 * `wp_check_filetype()` answers from the site's upload allowlist, which has
	 * no reason to contain Markdown, so the generated formats are named here.
	 */
	private const MIME_TYPES = [
		'md' => 'text/markdown',
		'html' => 'text/html',
		'json' => 'application/json',
		'txt' => 'text/plain',
	];

	/**
	 * @param string|callable $contents
	 */
	private function __construct( string $path, $contents ) {
		$this->path = ltrim( $path, '/' );
		$this->contents = $contents;
	}

	public static function from_contents( string $path, string $contents ): self {
		return new self( $path, $contents );
	}

	/**
	 * A file that is generated only if it is read.
	 *
	 * A string is never treated as a callable, so content that happens to name a
	 * PHP function is published as written; that is why this is a separate
	 * constructor rather than a type check inside one.
	 */
	public static function from_callback( string $path, callable $callback ): self {
		return new self( $path, $callback );
	}

	/**
	 * A file the author supplied, published at a URL of its own.
	 *
	 * It goes into the archive like any other file, but a caller that can only
	 * carry text — an MCP tool result, say — hands over the URL instead of
	 * reading a megabyte of image into a JSON response. This says where the file
	 * came from rather than what its bytes look like: PHP has no separate byte
	 * type, and an asset may well be text.
	 *
	 * @param string|callable $contents
	 */
	public static function from_asset( string $path, $contents, string $url, string $mime_type = '' ): self {
		$file = new self( $path, $contents );

		$file->is_asset = true;
		$file->url = $url;
		$file->mime_type = '' !== $mime_type ? $mime_type : null;

		return $file;
	}

	public function get_path(): string {
		return $this->path;
	}

	public function get_contents(): string {
		$contents = $this->contents;

		// A string is content. Anything else callable produces it now.
		if ( ! is_string( $contents ) && is_callable( $contents ) ) {
			$contents = $contents();
		}

		return (string) $contents;
	}

	/**
	 * What this file contains, for a caller that has to decide how to carry it.
	 *
	 * An uploaded file is whatever it was uploaded as. A generated one is text
	 * the package built as a string, named more precisely for the few formats
	 * where that matters.
	 */
	public function get_mime_type(): string {
		if ( $this->mime_type ) {
			return $this->mime_type;
		}

		$extension = strtolower( (string) pathinfo( $this->path, PATHINFO_EXTENSION ) );

		if ( isset( self::MIME_TYPES[ $extension ] ) ) {
			return self::MIME_TYPES[ $extension ];
		}

		// Whatever a package generates is text it built as a string, whether or
		// not the extension is one of the few worth naming. Only an upload can be
		// something else, and one that arrived without a type stays unknown.
		return $this->is_asset ? 'application/octet-stream' : 'text/plain';
	}

	public function is_asset(): bool {
		return $this->is_asset;
	}

	public function get_url(): ?string {
		return $this->url;
	}

	/**
	 * The same file, published inside a directory.
	 *
	 * Contents that have not been generated yet stay that way, so nesting one
	 * package inside another costs nothing until its files are read.
	 */
	public function in_directory( string $directory ): self {
		$file = clone $this;

		$file->path = sprintf( '%s/%s', rtrim( $directory, '/' ), $this->path );

		return $file;
	}
}
