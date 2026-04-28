<?php

declare( strict_types=1 );

namespace RadishConcepts\TwoFactor\Cli;

use RadishConcepts\TwoFactor\Roles;
use RadishConcepts\TwoFactor\Storage\UserMeta;
use WP_CLI;
use WP_Session_Tokens;
use WP_User;

/**
 * Lockout-recovery commands. Available when WP-CLI is loaded.
 *
 *   wp radish-2fa disable <user>   — Wipe TOTP secret + backup codes, kill sessions.
 *   wp radish-2fa status <user>    — Show enrollment state for a user.
 *
 * `<user>` accepts user-ID, user_login or email.
 */
final class Commands {

	private static ?self $instance = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	public function register(): void {
		if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}

		WP_CLI::add_command( 'radish-2fa disable', [ $this, 'cmd_disable' ] );
		WP_CLI::add_command( 'radish-2fa status', [ $this, 'cmd_status' ] );
	}

	public function cmd_disable( array $args ): void {
		$user = $this->resolve_user( $args[0] ?? '' );

		UserMeta::instance()->clear( $user->ID );
		WP_Session_Tokens::get_instance( $user->ID )->destroy_all();

		WP_CLI::success( sprintf( '2FA reset for %s (#%d). Sessions terminated.', $user->user_login, $user->ID ) );
	}

	public function cmd_status( array $args ): void {
		$user        = $this->resolve_user( $args[0] ?? '' );
		$is_enrolled = UserMeta::instance()->is_enrolled( $user->ID );
		$codes_left  = UserMeta::instance()->count_backup_codes( $user->ID );
		$enrolled_at = (int) get_user_meta( $user->ID, UserMeta::META_ENROLLED_AT, true );
		$last_used   = (int) get_user_meta( $user->ID, UserMeta::META_LAST_USED_AT, true );

		WP_CLI::log( sprintf( 'User:           %s (#%d)', $user->user_login, $user->ID ) );
		WP_CLI::log( sprintf( 'Enrolled:       %s', $is_enrolled ? 'yes' : 'no' ) );
		WP_CLI::log( sprintf( 'Enrolled at:    %s', $enrolled_at ? wp_date( 'Y-m-d H:i', $enrolled_at ) : '—' ) );
		WP_CLI::log( sprintf( 'Backup codes:   %d', $codes_left ) );
		WP_CLI::log( sprintf( 'Last used:      %s', $last_used ? wp_date( 'Y-m-d H:i', $last_used ) : '—' ) );
		WP_CLI::log( sprintf( 'Requires 2FA:   %s', Roles::instance()->user_requires_2fa( $user ) ? 'yes' : 'no' ) );
	}

	private function resolve_user( string $identifier ): WP_User {
		$identifier = trim( $identifier );
		if ( '' === $identifier ) {
			WP_CLI::error( 'Provide a user ID, login, or email.' );
		}

		$user = get_user_by( 'login', $identifier );
		if ( ! $user && is_email( $identifier ) ) {
			$user = get_user_by( 'email', $identifier );
		}
		if ( ! $user && ctype_digit( $identifier ) ) {
			$user = get_user_by( 'id', (int) $identifier );
		}

		if ( ! $user instanceof WP_User ) {
			WP_CLI::error( "User not found: {$identifier}" );
		}

		return $user;
	}

	private function __construct() {}
}
