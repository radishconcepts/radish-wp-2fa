<?php

declare( strict_types=1 );

namespace RadishConcepts\TwoFactor;

/**
 * Single source of truth for the 2FA frontend URLs.
 * The matching rewrite rule is registered in Frontend (batch 4).
 */
final class Routes {

	public const QUERY_VAR    = 'radish_2fa_action';
	public const TOKEN_PARAM  = 't';
	public const ACTION_SETUP     = 'setup';
	public const ACTION_CHALLENGE = 'challenge';

	private const SETUP_PATH     = '2fa/setup/';
	private const CHALLENGE_PATH = '2fa/challenge/';

	public static function setup_url( string $token ): string {
		return self::build( self::SETUP_PATH, $token );
	}

	public static function challenge_url( string $token ): string {
		return self::build( self::CHALLENGE_PATH, $token );
	}

	public static function setup_path(): string {
		return self::SETUP_PATH;
	}

	public static function challenge_path(): string {
		return self::CHALLENGE_PATH;
	}

	private static function build( string $path, string $token ): string {
		return add_query_arg( self::TOKEN_PARAM, $token, home_url( '/' . ltrim( $path, '/' ) ) );
	}
}
