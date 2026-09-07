<?php

namespace WPElevator\Agent_Pilot\MCP;

use WPElevator\Agent_Pilot\Agent_Package;
use WPElevator\Agent_Pilot\Package_File;
use WP_Error;

/**
 * Publishes the files of this site's packages as MCP resources.
 *
 * Every file a package publishes is addressable and readable over the same
 * authenticated connection the tools use. That is the difference from the URLs
 * the tools hand out: an unpublished package is served from a preview link that
 * needs a WordPress session, and an access token minted for this server is
 * deliberately not accepted anywhere else, so a client holding one could list a
 * draft skill and then fail to fetch it. Here the bytes come back through the
 * connection that already authenticated.
 *
 * A package is addressable as a whole as well as file by file. Reading the whole
 * one answers with every file it publishes at once, which the specification
 * allows a read to do, so a client gets a package in one round trip without any
 * of it being packed into an archive it would have to unpack again.
 *
 * The packages themselves arrive from a provider rather than from any post
 * query, so this knows only what `Agent_Package` promises.
 */
class Resources {

	/**
	 * The URI scheme these resources are addressed under.
	 *
	 * A custom scheme rather than `https://`, which the specification reserves
	 * for resources a client is expected to fetch from the web on its own.
	 */
	public const SCHEME = 'agent-pilot';

	/**
	 * Returns the packages to publish, keyed by the collection each belongs to.
	 *
	 * @var callable(): array<string, Agent_Package[]>
	 */
	private $provider;

	/**
	 * @param callable(): array<string, Agent_Package[]> $provider
	 */
	public function __construct( callable $provider ) {
		$this->provider = $provider;
	}

	/**
	 * Every resource the current caller may see.
	 *
	 * Naming them generates none of them: a package can list its files without
	 * producing any, and `size` is left out for the same reason, since knowing
	 * it would mean generating everything to measure it.
	 */
	public function get_resources(): array {
		$resources = [];

		foreach ( $this->get_packages() as $collection => $packages ) {
			foreach ( $packages as $package ) {
				$resources[] = $this->to_package_resource( $collection, $package );

				foreach ( $package->get_files()->get_paths() as $path ) {
					$resources[] = $this->to_resource( $collection, $package, $path );
				}
			}
		}

		return $resources;
	}

	/**
	 * Read one resource by URI.
	 *
	 * @return array|WP_Error The `contents` member of the result, or an error.
	 */
	public function read( string $uri ) {
		$target = $this->resolve( $uri );

		if ( ! $target ) {
			return new WP_Error(
				'agent_pilot_mcp_unknown_resource',
				sprintf(
					/* translators: %s: the requested resource URI. */
					__( 'Resource not found: %s', 'wpelevator-agent-pilot' ),
					$uri
				),
				[
					'code' => Json_Rpc::INVALID_PARAMS,
					'data' => [ 'uri' => $uri ],
				]
			);
		}

		list( $collection, $package, $file ) = $target;

		if ( $file instanceof Package_File ) {
			return [ $this->to_contents( $uri, $file ) ];
		}

		return $this->read_package( $collection, $package );
	}

	/**
	 * Every file of a package, read at once.
	 *
	 * Each one is carried the way it would be on its own, so text stays text
	 * instead of being packed into an archive the client would have to unpack to
	 * read a paragraph of Markdown.
	 */
	private function read_package( string $collection, Agent_Package $package ): array {
		$contents = [];
		$files = $package->get_files();

		foreach ( $files->get_paths() as $path ) {
			$file = $files->get( $path );

			if ( $file ) {
				$contents[] = $this->to_contents( $this->get_uri( $collection, $package, $path ), $file );
			}
		}

		return $contents;
	}

	/**
	 * The URI a package, or one file of it, is published under.
	 *
	 * The package itself is the bare URI, and its files hang below it, so the
	 * two can never collide: a file always has a path after the package name.
	 */
	public function get_uri( string $collection, Agent_Package $package, string $path = '' ): string {
		$uri = sprintf( '%s://%s/%s', self::SCHEME, $collection, $package->get_name() );

		return '' !== $path ? sprintf( '%s/%s', $uri, $path ) : $uri;
	}

	/**
	 * @return array<string, Agent_Package[]>
	 */
	private function get_packages(): array {
		return (array) call_user_func( $this->provider );
	}

	/**
	 * The package itself, which reads as every file it publishes.
	 *
	 * It carries no media type, having no single one: what comes back is a file
	 * per entry, each named with its own.
	 */
	private function to_package_resource( string $collection, Agent_Package $package ): array {
		$resource = [
			'uri' => $this->get_uri( $collection, $package ),
			'name' => $package->get_name(),
			'description' => sprintf(
				/* translators: %s: the package name. */
				__( 'Every file %s publishes, read at once.', 'wpelevator-agent-pilot' ),
				$package->get_name()
			),
		];

		return array_merge( $resource, $this->get_annotations( $package ) );
	}

	private function to_resource( string $collection, Agent_Package $package, string $path ): array {
		$file = $package->get_files()->get( $path );

		$resource = [
			'uri' => $this->get_uri( $collection, $package, $path ),
			'name' => sprintf( '%s/%s', $package->get_name(), $path ),
			'mimeType' => $file ? $file->get_mime_type() : 'text/plain',
		];

		return array_merge( $resource, $this->get_annotations( $package ) );
	}

	private function get_annotations( Agent_Package $package ): array {
		$last_modified = $package->get_last_modified();

		if ( ! $last_modified ) {
			return [];
		}

		return [
			'annotations' => [
				'lastModified' => gmdate( 'c', $last_modified ),
			],
		];
	}

	/**
	 * Text is sent as text and everything else as base64, which is the only way
	 * the protocol carries bytes that are not valid UTF-8.
	 */
	private function to_contents( string $uri, Package_File $file ): array {
		$mime_type = $file->get_mime_type();

		$contents = [
			'uri' => $uri,
			'mimeType' => $mime_type,
		];

		if ( $this->is_text( $mime_type ) ) {
			$contents['text'] = $file->get_contents();
		} else {
			$contents['blob'] = base64_encode( $file->get_contents() );
		}

		return $contents;
	}

	private function is_text( string $mime_type ): bool {
		return 0 === strpos( $mime_type, 'text/' )
			|| in_array( $mime_type, [ 'application/json', 'application/xml' ], true )
			|| (bool) preg_match( '#^application/[\w.-]+\+(json|xml)$#', $mime_type );
	}

	/**
	 * Resolve a URI back to the package it addresses, and to one of its files
	 * when the URI names one.
	 *
	 * The lookup runs over the packages the caller may see, so a URI naming one
	 * they may not read resolves to nothing, exactly as an unknown one does.
	 *
	 * @return array{0: string, 1: Agent_Package, 2: ?Package_File}|null
	 */
	private function resolve( string $uri ): ?array {
		$prefix = self::SCHEME . '://';

		if ( 0 !== strpos( $uri, $prefix ) ) {
			return null;
		}

		$segments = explode( '/', substr( $uri, strlen( $prefix ) ), 3 );

		if ( count( $segments ) < 2 ) {
			return null;
		}

		$collection = $segments[0];
		$name = $segments[1];
		$path = $segments[2] ?? '';

		foreach ( $this->get_packages()[ $collection ] ?? [] as $package ) {
			if ( $package->get_name() === $name ) {
				$file = '' !== $path ? $package->get_files()->get( $path ) : null;

				return ( '' === $path || $file ) ? [ $collection, $package, $file ] : null;
			}
		}

		return null;
	}
}
