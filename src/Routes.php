<?php

declare( strict_types=1 );

namespace RadishConcepts\TwoFactor;

use RadishConcepts\TwoFactor\Auth\Nonce;

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

	/**
	 * The only route a token of this mode may open.
	 *
	 * A challenge token is issued after the password step to users who already
	 * have 2FA. If it could open the setup route, that password alone would be
	 * enough to enrol a new authenticator over the user's own and sign in.
	 * Backup-mode tokens belong to setup: they show the new backup codes.
	 *
	 * @return string One of the ACTION_* constants, or '' for an unknown mode.
	 */
	public static function action_for_mode( string $mode ): string {
		return match ( $mode ) {
			Nonce::MODE_SETUP, Nonce::MODE_BACKUP => self::ACTION_SETUP,
			Nonce::MODE_CHALLENGE                 => self::ACTION_CHALLENGE,
			default                               => '',
		};
	}

	public static function url_for_action( string $action, string $token ): string {
		return self::ACTION_CHALLENGE === $action ? self::challenge_url( $token ) : self::setup_url( $token );
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
