<?php

declare( strict_types=1 );

namespace RadishConcepts\TwoFactor\Security;

/**
 * Limits wrong verification codes: at most 5 per 2FA token and 10 per user in
 * a rolling 15-minute window. Both counters are site transients, so they work
 * network-wide on multisite; the per-user one survives a fresh password login,
 * the per-token one outlives the nonce so a consumed token stays exhausted.
 *
 * Counters are read and written in separate steps, so parallel requests could
 * each read the same count and all get a guess checked. Callers hold a per-user
 * database lock (acquire/release) around the whole check, and must read the
 * counters for the first time inside that lock: WordPress caches a transient
 * per request after the first read.
 */
final class AttemptLimit {

	public const MAX_PER_TOKEN  = 5;
	public const MAX_PER_USER   = 10;
	public const WINDOW_SECONDS = 15 * MINUTE_IN_SECONDS;

	private const PREFIX       = 'r2fa_fail_';
	private const TOKEN_PREFIX = 'r2fa_tfail_';

	/** Matches the nonce's absolute lifetime, so a token can't outlive its count. */
	private const TOKEN_TTL = 30 * MINUTE_IN_SECONDS;

	/** Seconds to wait for a parallel check of the same user to finish. */
	private const LOCK_TIMEOUT = 5;

	private static ?self $instance = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	public function register(): void {
		// A password reset proves control of the mailbox and ends the attacker's
		// use of the old password, so let the real user straight back in.
		add_action( 'after_password_reset', [ $this, 'clear_for_user' ], 10, 1 );
	}

	public function clear_for_user( $user ): void {
		if ( $user instanceof \WP_User ) {
			$this->clear( (int) $user->ID );
		}
	}

	/**
	 * Take the per-user lock that serialises code checks.
	 *
	 * @return bool False only when a parallel check held the lock for too long.
	 *              True when locked, or when the database has no named locks
	 *              (e.g. SQLite), in which case checks run unserialised.
	 */
	public function acquire( int $user_id ): bool {
		global $wpdb;

		$result = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $this->lock_name( $user_id ), self::LOCK_TIMEOUT ) );

		return null === $result || '1' === (string) $result;
	}

	public function release( int $user_id ): void {
		global $wpdb;

		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $this->lock_name( $user_id ) ) );
	}

	/**
	 * Whether a token or user has used up its attempts.
	 */
	public static function is_exhausted( int $token_failures, int $user_failures ): bool {
		return $token_failures >= self::MAX_PER_TOKEN || $user_failures >= self::MAX_PER_USER;
	}

	public function is_locked( int $user_id ): bool {
		return $this->user_failures( $user_id ) >= self::MAX_PER_USER;
	}

	public function user_failures( int $user_id ): int {
		return count( $this->prune_window( $user_id ) );
	}

	public function token_failures( string $token ): int {
		return (int) get_site_transient( $this->token_key( $token ) );
	}

	/**
	 * @return int Wrong codes for this token, including this one.
	 */
	public function record_token_failure( string $token ): int {
		$count = $this->token_failures( $token ) + 1;
		set_site_transient( $this->token_key( $token ), $count, self::TOKEN_TTL );

		return $count;
	}

	/**
	 * Record a wrong code and log it.
	 *
	 * @return int Failures for this user inside the window, including this one.
	 */
	public function record_failure( int $user_id ): int {
		$timestamps   = $this->prune_window( $user_id );
		$timestamps[] = time();
		set_site_transient( self::PREFIX . $user_id, $timestamps, self::WINDOW_SECONDS );

		$count = count( $timestamps );

		error_log( sprintf(
			'[radish-2fa] Wrong verification code for user %d (%d of %d in %d minutes).',
			$user_id,
			$count,
			self::MAX_PER_USER,
			self::WINDOW_SECONDS / MINUTE_IN_SECONDS
		) );

		/**
		 * Fires after a wrong 2FA code was submitted.
		 *
		 * @param int $user_id User the code was submitted for.
		 * @param int $count   Wrong codes for this user inside the window.
		 */
		do_action( 'radish_2fa_verification_failed', $user_id, $count );

		return $count;
	}

	public function clear( int $user_id ): void {
		delete_site_transient( self::PREFIX . $user_id );
	}

	private function token_key( string $token ): string {
		return self::TOKEN_PREFIX . hash( 'sha256', $token );
	}

	/**
	 * MySQL lock names are server-wide and at most 64 characters, so scope them
	 * to this installation.
	 */
	private function lock_name( int $user_id ): string {
		global $wpdb;

		$install = defined( 'DB_NAME' ) ? DB_NAME : '';

		return 'r2fa_' . substr( md5( $install . '|' . $wpdb->base_prefix ), 0, 12 ) . '_' . $user_id;
	}

	/**
	 * @return int[] Timestamps still inside the rolling window.
	 */
	private function prune_window( int $user_id ): array {
		$stored = get_site_transient( self::PREFIX . $user_id );
		if ( ! is_array( $stored ) ) {
			return [];
		}

		$cutoff = time() - self::WINDOW_SECONDS;

		return array_values( array_filter(
			array_map( 'intval', $stored ),
			static fn ( int $ts ): bool => $ts >= $cutoff
		) );
	}

	private function __construct() {}
}
