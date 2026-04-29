<?php

declare( strict_types=1 );

namespace RadishConcepts\TwoFactor\Methods;

/**
 * Identifiers for the supported 2FA methods. A user has exactly one enrolled
 * method at a time; admins control which methods are even offered via the
 * `enabled_methods` setting.
 */
final class Method {

	public const TOTP  = 'totp';
	public const EMAIL = 'email';

	/**
	 * @return string[]
	 */
	public static function all(): array {
		return [ self::TOTP, self::EMAIL ];
	}

	public static function is_valid( string $method ): bool {
		return in_array( $method, self::all(), true );
	}

	public static function label( string $method ): string {
		return match ( $method ) {
			self::TOTP  => __( 'Authenticator app', 'radish-2fa' ),
			self::EMAIL => __( 'Email', 'radish-2fa' ),
			default     => $method,
		};
	}

	private function __construct() {}
}
