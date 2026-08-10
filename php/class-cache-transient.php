<?php

namespace WPElevator\Agent_Pilot;

/**
 * Note that persistent object cache will be used, if available.
 */
class Cache_Transient {
	private string $cache_group;

	public function __construct( string $cache_group ) {
		$this->cache_group = $cache_group;
	}

	private function get_key( string $key ): string {
		return sprintf( '%s-%s', $this->cache_group, $key );
	}

	public function get( string $key, ?callable $resolve = null, ?int $expiration = 0 ) {
		$transient_key = $this->get_key( $key );
		$value = get_transient( $transient_key );

		if ( false === $value && $resolve ) {
			$value = call_user_func( $resolve );

			if ( false !== $value ) {
				set_transient( $transient_key, $value, $expiration );
			}
		}

		return $value;
	}

	public function set( string $key, $value, int $expiration = 0 ): bool {
		$transient_key = $this->get_key( $key );

		return set_transient( $transient_key, $value, $expiration );
	}
}
