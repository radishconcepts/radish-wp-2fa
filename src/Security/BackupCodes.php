<?php

declare( strict_types=1 );

namespace RadishConcepts\TwoFactor\Security;

final class BackupCodes {

	public const COUNT  = 10;
	public const LENGTH = 8;

	private static ?self $instance = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	/**
	 * Generate fresh plaintext codes (formatted XXXX-XXXX).
	 *
	 * @return string[] List of plaintext codes — show ONCE, never store.
	 */
	public function generate( int $count = self::COUNT ): array {
		$codes = [];
		for ( $i = 0; $i < $count; $i++ ) {
			$codes[] = $this->format( wp_generate_password( self::LENGTH, false, false ) );
		}

		return $codes;
	}

	/**
	 * Hash plaintext codes for storage. Uses WP's password hasher (bcrypt-based).
	 *
	 * @param string[] $plain_codes
	 * @return string[] Hashes ready to persist in user meta.
	 */
	public function hash_all( array $plain_codes ): array {
		return array_values( array_map( static fn ( string $code ) => wp_hash_password( self::normalize( $code ) ), $plain_codes ) );
	}

	/**
	 * Verify a user-submitted code against a list of stored hashes.
	 * Returns the index of the matched hash, or null if none match.
	 *
	 * @param string[] $hashes
	 */
	public function find_match( string $submitted, array $hashes ): ?int {
		$normalized = self::normalize( $submitted );
		if ( '' === $normalized ) {
			return null;
		}

		foreach ( $hashes as $index => $hash ) {
			if ( ! is_string( $hash ) || '' === $hash ) {
				continue;
			}
			if ( wp_check_password( $normalized, $hash ) ) {
				return (int) $index;
			}
		}

		return null;
	}

	private function format( string $raw ): string {
		$upper = strtoupper( $raw );
		$half  = (int) ( self::LENGTH / 2 );

		return substr( $upper, 0, $half ) . '-' . substr( $upper, $half );
	}

	public static function normalize( string $code ): string {
		return strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', $code ) ?? '' );
	}

	private function __construct() {}
}
