<?php

declare( strict_types=1 );

namespace RadishConcepts\TwoFactor\Storage;

use RadishConcepts\TwoFactor\Security\Crypto;

/**
 * User-meta storage for 2FA state. All meta is global per user (network-wide on multisite).
 */
final class UserMeta {

	public const META_SECRET       = '_radish_2fa_secret';
	public const META_ENROLLED_AT  = '_radish_2fa_enrolled_at';
	public const META_BACKUP_CODES = '_radish_2fa_backup_codes';
	public const META_LAST_USED_AT = '_radish_2fa_last_used_at';

	private static ?self $instance = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	public function is_enrolled( int $user_id ): bool {
		return null !== $this->get_secret( $user_id );
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
	}

	public function clear( int $user_id ): void {
		delete_user_meta( $user_id, self::META_SECRET );
		delete_user_meta( $user_id, self::META_ENROLLED_AT );
		delete_user_meta( $user_id, self::META_BACKUP_CODES );
		delete_user_meta( $user_id, self::META_LAST_USED_AT );
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
