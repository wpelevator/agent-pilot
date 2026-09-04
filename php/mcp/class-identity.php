<?php

namespace WPElevator\Agent_Pilot\MCP;

/**
 * Who is making an MCP request, and how far their credential reaches.
 *
 * Deliberately a local value object rather than OAuth Pilot's `Token\Context`,
 * so that nothing in the MCP server type hints against a class that may not be
 * installed. It carries only what the tool layer needs to make decisions.
 */
class Identity {

	private int $user_id;

	/**
	 * The granted OAuth scopes, or null when the request was authenticated by
	 * WordPress itself rather than by a token.
	 *
	 * @var string[]|null
	 */
	private ?array $scopes;

	private string $client_id;

	/**
	 * @param string[]|null $scopes
	 */
	private function __construct( int $user_id, ?array $scopes, string $client_id = '' ) {
		$this->user_id = $user_id;
		$this->scopes = $scopes;
		$this->client_id = $client_id;
	}

	/**
	 * A request carrying a validated OAuth access token.
	 *
	 * The scopes are the ones stored on the token, which OAuth Pilot already
	 * expanded through its implication graph at issuance. That is what lets
	 * `has_scope()` below be a plain membership test: a `wp:write` token
	 * carries `wp:read` in storage.
	 *
	 * @param string[] $scopes
	 */
	public static function from_token( int $user_id, array $scopes, string $client_id ): self {
		return new self( $user_id, array_values( $scopes ), $client_id );
	}

	/**
	 * A request authenticated by WordPress itself, through a cookie or an
	 * Application Password. There is no token to narrow, so every scope the
	 * server knows about is considered granted and the user's own capabilities
	 * remain the only limit.
	 */
	public static function from_wordpress_user( int $user_id ): self {
		return new self( $user_id, null );
	}

	public function get_user_id(): int {
		return $this->user_id;
	}

	public function get_client_id(): string {
		return $this->client_id;
	}

	public function is_token(): bool {
		return null !== $this->scopes;
	}

	/**
	 * @return string[]
	 */
	public function get_scopes(): array {
		return $this->scopes ?? [];
	}

	public function has_scope( string $scope ): bool {
		if ( null === $this->scopes ) {
			return true;
		}

		return in_array( $scope, $this->scopes, true );
	}

	/**
	 * @param string[] $scopes
	 */
	public function has_scopes( array $scopes ): bool {
		foreach ( $scopes as $scope ) {
			if ( ! $this->has_scope( (string) $scope ) ) {
				return false;
			}
		}

		return true;
	}
}
