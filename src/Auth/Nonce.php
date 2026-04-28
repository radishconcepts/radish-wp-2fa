<?php

declare( strict_types=1 );

namespace RadishConcepts\TwoFactor\Auth;

/**
 * One-time login nonce, used to bridge the password-auth step and the 2FA
 * verification page. Stored in a site transient (network-wide on multisite),
 * keyed by SHA-256(token) so a DB read can't recover the token itself.
 */
final class Nonce {

	public const MODE_SETUP     = 'setup';
	public const MODE_CHALLENGE = 'challenge';
	public const MODE_BACKUP    = 'backup';

	private const TTL    = 5 * MINUTE_IN_SECONDS;
	private const PREFIX = 'r2fa_n_';

	private static ?self $instance = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	/**
	 * Create a fresh nonce. Returns the token to send to the client.
	 *
	 * @param array<string,mixed> $extra Additional fields merged into the payload (e.g. pending TOTP secret, remember flag).
	 */
	public function create( int $user_id, string $mode, string $redirect_to, array $extra = [] ): string {
		$token = bin2hex( random_bytes( 16 ) );

		set_site_transient(
			$this->key( $token ),
			array_merge(
				$extra,
				[
					'user_id'     => $user_id,
					'mode'        => $mode,
					'redirect_to' => $redirect_to,
				]
			),
			self::TTL
		);

		return $token;
	}

	/**
	 * Replace the payload of an existing nonce while keeping the same token. Used when
	 * advancing the flow state (e.g. setup → backup-codes display) without rotating tokens.
	 *
	 * @param array<string,mixed> $payload
	 */
	public function update( string $token, array $payload ): void {
		if ( '' === $token ) {
			return;
		}

		set_site_transient( $this->key( $token ), $payload, self::TTL );
	}

	/**
	 * Return the nonce payload without consuming it. Use for form rendering and
	 * pre-auth validation; only consume() after a fully successful 2FA verify.
	 *
	 * @return array{user_id:int,mode:string,redirect_to:string}|null
	 */
	public function peek( string $token ): ?array {
		if ( '' === $token ) {
			return null;
		}

		$payload = get_site_transient( $this->key( $token ) );

		return is_array( $payload ) && isset( $payload['user_id'], $payload['mode'] ) ? $payload : null;
	}

	public function consume( string $token ): void {
		if ( '' === $token ) {
			return;
		}

		delete_site_transient( $this->key( $token ) );
	}

	private function key( string $token ): string {
		return self::PREFIX . hash( 'sha256', $token );
	}

	private function __construct() {}
}
