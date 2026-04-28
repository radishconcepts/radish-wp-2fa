<?php

declare( strict_types=1 );

namespace RadishConcepts\TwoFactor\Security;

use RuntimeException;

/**
 * Symmetric encryption for sensitive user meta (TOTP secrets).
 *
 * Encryption key is derived from WordPress' AUTH_KEY + SECURE_AUTH_KEY constants,
 * so a database dump alone is not enough to recover plaintext secrets — the
 * attacker also needs the wp-config.php constants.
 */
final class Crypto {

	private const VERSION_MARKER = 'r2fav1';

	public static function encrypt( string $plain ): string {
		self::guard_sodium();

		$key   = self::derive_key();
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = sodium_crypto_secretbox( $plain, $nonce, $key );

		sodium_memzero( $key );

		return self::VERSION_MARKER . ':' . sodium_bin2base64( $nonce . $cipher, SODIUM_BASE64_VARIANT_ORIGINAL );
	}

	public static function decrypt( string $payload ): ?string {
		self::guard_sodium();

		$prefix = self::VERSION_MARKER . ':';
		if ( ! str_starts_with( $payload, $prefix ) ) {
			return null;
		}

		try {
			$raw = sodium_base642bin( substr( $payload, strlen( $prefix ) ), SODIUM_BASE64_VARIANT_ORIGINAL );
		} catch ( \SodiumException ) {
			return null;
		}

		if ( strlen( $raw ) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}

		$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		$key   = self::derive_key();
		$plain = sodium_crypto_secretbox_open( $cipher, $nonce, $key );
		sodium_memzero( $key );

		return false === $plain ? null : $plain;
	}

	private static function derive_key(): string {
		$auth        = defined( 'AUTH_KEY' ) ? (string) AUTH_KEY : '';
		$secure_auth = defined( 'SECURE_AUTH_KEY' ) ? (string) SECURE_AUTH_KEY : '';

		if ( '' === $auth || '' === $secure_auth ) {
			throw new RuntimeException( 'AUTH_KEY and SECURE_AUTH_KEY must be defined in wp-config.php for radish-2fa.' );
		}

		return hash_hkdf(
			'sha256',
			$auth . $secure_auth,
			SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
			'radish-2fa:totp:v1'
		);
	}

	private static function guard_sodium(): void {
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			throw new RuntimeException( 'libsodium is required for radish-2fa (PHP 7.2+ ships with it built-in).' );
		}
	}
}
