<?php

namespace WPElevator\Agent_Pilot;

/**
 * Something that publishes itself as a named set of files.
 *
 * This is the whole of what packaging needs: a name to publish under, the files
 * to publish, a fingerprint to cache them by, and when they last changed. A
 * skill and an Agent Plugin are both posts today, which is why both are built
 * on `Post`, but nothing here says so — a package assembled from blocks that
 * were never saved as a post would satisfy this contract just as well, and
 * everything that only packages, archives or serves one already asks for this
 * rather than for a post.
 */
interface Agent_Package {

	/**
	 * The name this package publishes under, which is also its directory and
	 * archive name.
	 */
	public function get_name(): string;

	public function get_files(): Agent_Package_Files;

	/**
	 * Whether this package is published, and so may be served to anyone.
	 *
	 * A package that bundles others has to ask them this: publishing a package
	 * publicly publishes everything inside it, so bundling something that is not
	 * itself published would leak it.
	 */
	public function is_published(): bool;

	/**
	 * A fingerprint that changes whenever the published files would.
	 *
	 * It is a cache key for the generated archive, so it has to account for
	 * everything the files are built from, including content the package only
	 * links to.
	 */
	public function get_hash(): string;

	/**
	 * When this package last changed, as a Unix timestamp.
	 */
	public function get_last_modified(): ?int;
}
