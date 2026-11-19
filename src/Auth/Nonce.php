<?php

declare( strict_types=1 );

namespace RadishConcepts\TwoFactor\Auth;

use RadishConcepts\TwoFactor\Security\Crypto;

/**
 * One-time login nonce, used to bridge the password-auth step and the 2FA
 * verification page.
 *
 * Stored in user meta (network-wide on multisite), keyed by SHA-256(token) so
 * a DB read can't recover the token itself, and encrypted because the payload
 * can hold a pending TOTP secret or plaintext backup codes. Not in a transient:
 * with a persistent object cache a transient lives only in the cache, so a
 * `wp cache flush` mid-setup threw the pending secret away and the user's
 * authenticator app was left with codes nothing would accept.
 *
 * Expiry is checked on every read. Rows nobody reads again are purged the next
 * time the same user starts a flow.
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

	private const PREFIX         = '_radish_2fa_flow_';
	private const RECEIPT_PREFIX = '_radish_2fa_receipt_';

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

		$this->purge_expired( $user_id );

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

		$payload = $this->read( $this->key( $token ) );

		return is_array( $payload ) && isset( $payload['user_id'], $payload['mode'] ) ? $payload : null;
	}

	public function consume( string $token ): void {
		if ( '' === $token ) {
			return;
		}

		$this->forget( $this->key( $token ) );
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

		$this->write(
			$user_id,
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

		$payload = $this->read( $this->receipt_key( $token ) );

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

		$this->write( (int) ( $payload['user_id'] ?? 0 ), $this->key( $token ), $payload, $ttl );
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

	/**
	 * @param array<string,mixed> $data
	 */
	private function write( int $user_id, string $meta_key, array $data, int $ttl ): void {
		if ( $user_id <= 0 ) {
			return;
		}

		$record = wp_json_encode(
			[
				'expires_at' => time() + $ttl,
				'data'       => $data,
			]
		);
		if ( false === $record ) {
			return;
		}

		update_user_meta( $user_id, $meta_key, Crypto::encrypt( $record ) );
	}

	/**
	 * The stored data, or null when there is none or it has expired. An expired
	 * or unreadable row is deleted on the way.
	 *
	 * @return array<string,mixed>|null
	 */
	private function read( string $meta_key ): ?array {
		$row = $this->locate( $meta_key );
		if ( null === $row ) {
			return null;
		}

		$record = $this->unseal( $row['meta_value'] );
		if ( null === $record ) {
			delete_user_meta( $row['user_id'], $meta_key );

			return null;
		}

		return $record['data'];
	}

	private function forget( string $meta_key ): void {
		$row = $this->locate( $meta_key );
		if ( null !== $row ) {
			delete_user_meta( $row['user_id'], $meta_key );
		}
	}

	/**
	 * Find the row by its meta key alone: the token is all a request carries, the
	 * user it belongs to is what the row says. Read from the database, not the
	 * user meta cache, which would need the user ID first.
	 *
	 * @return array{user_id:int,meta_value:string}|null
	 */
	private function locate( string $meta_key ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s LIMIT 1", $meta_key ),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return null;
		}

		return [
			'user_id'    => (int) $row['user_id'],
			'meta_value' => (string) $row['meta_value'],
		];
	}

	/**
	 * Drop the user's flows and receipts that expired without being read again,
	 * such as a setup page left open in a tab nobody went back to.
	 */
	private function purge_expired( int $user_id ): void {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_key, meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND ( meta_key LIKE %s OR meta_key LIKE %s )",
				$user_id,
				$wpdb->esc_like( self::PREFIX ) . '%',
				$wpdb->esc_like( self::RECEIPT_PREFIX ) . '%'
			),
			ARRAY_A
		);

		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			if ( null === $this->unseal( (string) $row['meta_value'] ) ) {
				delete_user_meta( $user_id, (string) $row['meta_key'] );
			}
		}
	}

	/**
	 * Decrypt a stored row. Null when it can't be read or has expired.
	 *
	 * @return array{expires_at:int,data:array<string,mixed>}|null
	 */
	private function unseal( string $sealed ): ?array {
		$plain = Crypto::decrypt( $sealed );
		if ( null === $plain ) {
			return null;
		}

		$record = json_decode( $plain, true );
		if ( ! is_array( $record ) || ! is_array( $record['data'] ?? null ) ) {
			return null;
		}

		if ( (int) ( $record['expires_at'] ?? 0 ) <= time() ) {
			return null;
		}

		return [
			'expires_at' => (int) $record['expires_at'],
			'data'       => $record['data'],
		];
	}

	private function __construct() {}
}
