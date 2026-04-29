<?php

declare( strict_types=1 );

namespace RadishConcepts\TwoFactor\Security;

/**
 * Rate-limits OTP email sends per user: a 30-second cooldown between requests
 * and at most 5 sends per rolling hour. Backed by site transients so it works
 * network-wide on multisite.
 */
final class EmailRateLimit {

	public const COOLDOWN_SECONDS = 30;
	public const HOURLY_MAX       = 5;
	public const WINDOW_SECONDS   = HOUR_IN_SECONDS;

	private const PREFIX_COOLDOWN = 'r2fa_email_cd_';
	private const PREFIX_WINDOW   = 'r2fa_email_wd_';

	private static ?self $instance = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	public function can_send( int $user_id ): bool {
		if ( $this->seconds_until_next_send( $user_id ) > 0 ) {
			return false;
		}

		return count( $this->prune_window( $user_id ) ) < self::HOURLY_MAX;
	}

	public function seconds_until_next_send( int $user_id ): int {
		$cooldown_until = (int) get_site_transient( self::PREFIX_COOLDOWN . $user_id );
		if ( $cooldown_until > time() ) {
			return $cooldown_until - time();
		}

		return 0;
	}

	public function record_send( int $user_id ): void {
		set_site_transient( self::PREFIX_COOLDOWN . $user_id, time() + self::COOLDOWN_SECONDS, self::COOLDOWN_SECONDS );

		$timestamps   = $this->prune_window( $user_id );
		$timestamps[] = time();
		set_site_transient( self::PREFIX_WINDOW . $user_id, $timestamps, self::WINDOW_SECONDS );
	}

	public function clear( int $user_id ): void {
		delete_site_transient( self::PREFIX_COOLDOWN . $user_id );
		delete_site_transient( self::PREFIX_WINDOW . $user_id );
	}

	/**
	 * @return int[] timestamps still inside the rolling window
	 */
	private function prune_window( int $user_id ): array {
		$stored = get_site_transient( self::PREFIX_WINDOW . $user_id );
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
