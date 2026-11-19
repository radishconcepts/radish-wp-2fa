<?php

declare( strict_types=1 );

namespace RadishConcepts\TwoFactor\Storage;

use RadishConcepts\TwoFactor\Methods\Method;
use RadishConcepts\TwoFactor\Security\Crypto;

/**
 * User-meta storage for 2FA state. All meta is global per user (network-wide on multisite).
 */
final class UserMeta {

	public const META_SECRET             = '_radish_2fa_secret';
	public const META_ENROLLED_AT        = '_radish_2fa_enrolled_at';
	public const META_BACKUP_CODES       = '_radish_2fa_backup_codes';
	public const META_LAST_USED_AT       = '_radish_2fa_last_used_at';
	public const META_METHOD             = '_radish_2fa_method';
	public const META_PENDING_METHOD     = '_radish_2fa_pending_method';
	public const META_SETUP_RESTARTED_AT = '_radish_2fa_setup_restarted_at';

	/**
	 * How long a restarted setup still earns a notice. Long enough to cover the
	 * redirect to the new setup page, short enough that a user who walked away
	 * isn't told about it the next day.
	 */
	public const SETUP_RESTART_NOTICE_TTL = 15 * MINUTE_IN_SECONDS;

	private static ?self $instance = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	public function is_enrolled( int $user_id ): bool {
		$method = $this->get_method( $user_id );

		if ( Method::TOTP === $method ) {
			return null !== $this->get_secret( $user_id );
		}

		if ( Method::EMAIL === $method ) {
			return true;
		}

		// Back-compat: pre-method releases stored a TOTP secret without META_METHOD.
		return null !== $this->get_secret( $user_id );
	}

	public function get_method( int $user_id ): ?string {
		$stored = get_user_meta( $user_id, self::META_METHOD, true );
		if ( is_string( $stored ) && Method::is_valid( $stored ) ) {
			return $stored;
		}

		// Lazy back-compat: a leftover TOTP secret implies the user is on TOTP.
		if ( null !== $this->get_secret( $user_id ) ) {
			return Method::TOTP;
		}

		return null;
	}

	public function set_method( int $user_id, string $method ): void {
		if ( ! Method::is_valid( $method ) ) {
			return;
		}
		update_user_meta( $user_id, self::META_METHOD, $method );
	}

	public function get_secret( int $user_id ): ?string {
		$payload = get_user_meta( $user_id, self::META_SECRET, true );
		if ( ! is_string( $payload ) || '' === $payload ) {
			return null;
		}

		return Crypto::decrypt( $payload );
	}

	public function set_secret( int $user_id, string $secret ): void {
		update_user_meta( $user_id, self::META_SECRET, Crypto::encrypt( $secret ) );
		update_user_meta( $user_id, self::META_ENROLLED_AT, time() );
		update_user_meta( $user_id, self::META_METHOD, Method::TOTP );
	}

	public function enroll_email( int $user_id ): void {
		delete_user_meta( $user_id, self::META_SECRET );
		update_user_meta( $user_id, self::META_ENROLLED_AT, time() );
		update_user_meta( $user_id, self::META_METHOD, Method::EMAIL );
	}

	public function clear( int $user_id ): void {
		delete_user_meta( $user_id, self::META_SECRET );
		delete_user_meta( $user_id, self::META_ENROLLED_AT );
		delete_user_meta( $user_id, self::META_BACKUP_CODES );
		delete_user_meta( $user_id, self::META_LAST_USED_AT );
		delete_user_meta( $user_id, self::META_METHOD );
		delete_user_meta( $user_id, self::META_PENDING_METHOD );
		delete_user_meta( $user_id, self::META_SETUP_RESTARTED_AT );
	}

	/**
	 * Remember that this user's setup token vanished before setup finished, so
	 * the next setup page can say its QR code (or emailed code) is a new one.
	 * Kept in user meta rather than a transient, so an object cache flush can't
	 * drop it.
	 */
	public function mark_setup_restarted( int $user_id ): void {
		update_user_meta( $user_id, self::META_SETUP_RESTARTED_AT, time() );
	}

	/**
	 * Whether a recent setup restart still has to be reported. Reports it once.
	 */
	public function take_setup_restarted( int $user_id ): bool {
		$restarted_at = (int) get_user_meta( $user_id, self::META_SETUP_RESTARTED_AT, true );
		if ( 0 === $restarted_at ) {
			return false;
		}

		delete_user_meta( $user_id, self::META_SETUP_RESTARTED_AT );

		return time() - $restarted_at <= self::SETUP_RESTART_NOTICE_TTL;
	}

	/**
	 * @return string[] Stored bcrypt hashes (NOT plaintext codes).
	 */
	public function get_backup_code_hashes( int $user_id ): array {
		$stored = get_user_meta( $user_id, self::META_BACKUP_CODES, true );

		return is_array( $stored ) ? array_values( array_filter( $stored, 'is_string' ) ) : [];
	}

	/**
	 * @param string[] $hashes
	 */
	public function set_backup_code_hashes( int $user_id, array $hashes ): void {
		update_user_meta( $user_id, self::META_BACKUP_CODES, array_values( $hashes ) );
	}

	public function consume_backup_code( int $user_id, int $index ): void {
		$hashes = $this->get_backup_code_hashes( $user_id );
		unset( $hashes[ $index ] );
		$this->set_backup_code_hashes( $user_id, array_values( $hashes ) );
	}

	public function count_backup_codes( int $user_id ): int {
		return count( $this->get_backup_code_hashes( $user_id ) );
	}

	public function mark_used( int $user_id ): void {
		update_user_meta( $user_id, self::META_LAST_USED_AT, time() );
	}

	private function __construct() {}
}
