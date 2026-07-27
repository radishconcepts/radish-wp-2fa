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

	/** Idle window: refreshed on every interaction with the 2FA pages. */
	private const TTL = 5 * MINUTE_IN_SECONDS;

	/** Hard ceiling on a nonce's lifetime, however active the visitor is. */
	private const TTL_ABSOLUTE = 30 * MINUTE_IN_SECONDS;

	/**
	 * How long a completed token stays recognisable. Only long enough to cover a
	 * repeat of the request that finished the flow; the receipt carries no
	 * authentication power of its own.
	 */
	private const RECEIPT_TTL = 15 * MINUTE_IN_SECONDS;

	private const PREFIX         = 'r2fa_n_';
	private const RECEIPT_PREFIX = 'r2fa_c_';

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

		$this->store(
			$token,
			array_merge(
				$extra,
				[
					'user_id'     => $user_id,
					'mode'        => $mode,
					'redirect_to' => $redirect_to,
					'created_at'  => time(),
				]
			)
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

		if ( ! isset( $payload['created_at'] ) ) {
			$existing = $this->peek( $token );
			if ( null !== $existing && isset( $existing['created_at'] ) ) {
				$payload['created_at'] = $existing['created_at'];
			}
		}

		$this->store( $token, $payload );
	}

	/**
	 * Restart the idle window without touching the payload. Called on every 2FA page
	 * view so a visitor who is demonstrably still working through the flow (waiting
	 * for a mail, fetching their phone) isn't cut off mid-way by the 5-minute TTL.
	 */
	public function touch( string $token ): void {
		$payload = $this->peek( $token );
		if ( null === $payload ) {
			return;
		}

		$this->store( $token, $payload );
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

	/**
	 * Consume a nonce after a successful sign-in and leave a receipt behind.
	 *
	 * Without the receipt, a *repeat* of the request that completed the flow — a
	 * double-clicked submit button, the browser re-sending the POST after back or
	 * reload, a restored tab — finds no nonce and is indistinguishable from an
	 * expired link, so the visitor is told the session expired while in fact they
	 * are signed in. The receipt lets that repeat resolve to the destination
	 * instead. It stores no secret and grants nothing: it only names where the
	 * finished flow was headed.
	 */
	public function complete( string $token, int $user_id, string $redirect_to ): void {
		$this->consume( $token );

		if ( '' === $token ) {
			return;
		}

		set_site_transient(
			$this->receipt_key( $token ),
			[
				'user_id'     => $user_id,
				'redirect_to' => $redirect_to,
			],
			self::RECEIPT_TTL
		);
	}

	/**
	 * Look up the receipt of an already-completed flow.
	 *
	 * @return array{user_id:int,redirect_to:string}|null
	 */
	public function peek_completed( string $token ): ?array {
		if ( '' === $token ) {
			return null;
		}

		$payload = get_site_transient( $this->receipt_key( $token ) );

		return is_array( $payload ) && isset( $payload['user_id'] ) ? $payload : null;
	}

	/**
	 * Persist a payload with the idle TTL, clamped to the absolute lifetime.
	 * A nonce that has outlived the ceiling is dropped rather than rewritten.
	 *
	 * @param array<string,mixed> $payload
	 */
	private function store( string $token, array $payload ): void {
		if ( ! isset( $payload['created_at'] ) ) {
			$payload['created_at'] = time();
		}

		$ttl = $this->remaining_ttl( (int) $payload['created_at'] );
		if ( $ttl <= 0 ) {
			$this->consume( $token );

			return;
		}

		set_site_transient( $this->key( $token ), $payload, $ttl );
	}

	private function remaining_ttl( int $created_at ): int {
		$until_ceiling = ( $created_at + self::TTL_ABSOLUTE ) - time();

		return (int) min( self::TTL, max( 0, $until_ceiling ) );
	}

	private function key( string $token ): string {
		return self::PREFIX . hash( 'sha256', $token );
	}

	private function receipt_key( string $token ): string {
		return self::RECEIPT_PREFIX . hash( 'sha256', $token );
	}

	private function __construct() {}
}
