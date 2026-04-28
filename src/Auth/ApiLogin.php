<?php

declare( strict_types=1 );

namespace RadishConcepts\TwoFactor\Auth;

use RadishConcepts\TwoFactor\Roles;
use WP_Error;
use WP_User;

/**
 * Block REST/XML-RPC password logins for users that require 2FA.
 * Application Passwords (HTTP Basic) are still allowed — that's the supported
 * machine-to-machine path for 2FA-protected accounts.
 */
final class ApiLogin {

	private static ?self $instance = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	public function register(): void {
		add_filter( 'authenticate', [ $this, 'block_password_api_login' ], 31, 1 );
	}

	public function block_password_api_login( $user ) {
		if ( ! $user instanceof WP_User ) {
			return $user;
		}

		if ( ! Roles::instance()->user_requires_2fa( $user ) ) {
			return $user;
		}

		if ( ! $this->is_api_request() ) {
			return $user;
		}

		if ( $this->is_application_password_attempt() ) {
			return $user;
		}

		return new WP_Error(
			'radish_2fa_api_login_disabled',
			__( 'Password-based API login is disabled for accounts with two-factor authentication. Use an Application Password.', 'radish-2fa' ),
			[ 'status' => 401 ]
		);
	}

	private function is_api_request(): bool {
		return ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST )
			|| ( defined( 'REST_REQUEST' ) && REST_REQUEST );
	}

	private function is_application_password_attempt(): bool {
		$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';

		return is_string( $auth ) && stripos( $auth, 'Basic ' ) === 0;
	}

	private function __construct() {}
}
