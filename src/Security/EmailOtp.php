<?php

declare( strict_types=1 );

namespace RadishConcepts\TwoFactor\Security;

/**
 * Short-lived numeric one-time password used for email-based 2FA. The plaintext
 * code is sent to the user's WP profile email address; only its bcrypt hash is
 * persisted (in the Nonce payload) for verification.
 */
final class EmailOtp {

	public const LENGTH = 6;
	public const TTL    = 10 * MINUTE_IN_SECONDS;

	private static ?self $instance = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	public function generate(): string {
		$code = '';
		for ( $i = 0; $i < self::LENGTH; $i++ ) {
			$code .= (string) random_int( 0, 9 );
		}

		return $code;
	}

	public function hash( string $code ): string {
		return wp_hash_password( self::normalize( $code ) );
	}

	public function verify( string $submitted, string $hash, int $expires_at ): bool {
		if ( '' === $hash || '' === $submitted ) {
			return false;
		}
		if ( time() > $expires_at ) {
			return false;
		}

		$normalized = self::normalize( $submitted );
		if ( strlen( $normalized ) !== self::LENGTH || ! ctype_digit( $normalized ) ) {
			return false;
		}

		return wp_check_password( $normalized, $hash );
	}

	public static function normalize( string $code ): string {
		return preg_replace( '/\s+/', '', $code ) ?? '';
	}

	private function __construct() {}
}
