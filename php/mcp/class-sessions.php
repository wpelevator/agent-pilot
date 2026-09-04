<?php

namespace WPElevator\Agent_Pilot\MCP;

/**
 * Legacy era `Mcp-Session-Id` handling.
 *
 * Only the initialization based revisions have sessions at all; the current
 * revision is stateless and never reaches this class. A session here holds no
 * authority — every request is authenticated on its own credential — it only
 * remembers which protocol version was negotiated, so the server knows which
 * result shape a later request expects.
 */
class Sessions {

	public const HEADER = 'Mcp-Session-Id';

	private const TRANSIENT_PREFIX = 'agent_pilot_mcp_session_';

	private const LIFETIME = 12 * HOUR_IN_SECONDS;

	/**
	 * Start a session and return its id.
	 *
	 * `wp_generate_uuid4()` satisfies the spec's requirements for the value:
	 * cryptographically secure, globally unique, and visible ASCII only.
	 */
	public function create( string $protocol_version, int $user_id ): string {
		$id = wp_generate_uuid4();

		set_transient(
			$this->get_transient_key( $id ),
			[
				'protocol_version' => $protocol_version,
				'user_id' => $user_id,
				'created' => time(),
			],
			self::LIFETIME
		);

		return $id;
	}

	public function get( string $id ): ?array {
		if ( ! $this->is_valid_id( $id ) ) {
			return null;
		}

		$session = get_transient( $this->get_transient_key( $id ) );

		return is_array( $session ) ? $session : null;
	}

	public function exists( string $id ): bool {
		return null !== $this->get( $id );
	}

	public function delete( string $id ): bool {
		if ( ! $this->is_valid_id( $id ) ) {
			return false;
		}

		return (bool) delete_transient( $this->get_transient_key( $id ) );
	}

	/**
	 * Session ids must contain only visible ASCII, and this one is always a
	 * UUID we generated. Checking the shape before touching the options table
	 * keeps an arbitrary header out of a transient key.
	 */
	public function is_valid_id( string $id ): bool {
		return (bool) preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id );
	}

	private function get_transient_key( string $id ): string {
		return self::TRANSIENT_PREFIX . $id;
	}
}
