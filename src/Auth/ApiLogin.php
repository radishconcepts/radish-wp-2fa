<?php

declare( strict_types=1 );

namespace RadishConcepts\TwoFactor\Auth;

use RadishConcepts\TwoFactor\Roles;
use WP_Error;
use WP_User;

/**
 * Block REST/XML-RPC password logins for users that require 2FA.
 * Application Passwords are still allowed: that's the supported
 * machine-to-machine path for 2FA-protected accounts. Only a login that core
 * itself authenticated with an application password counts; the mere presence
 * of an `Authorization: Basic` header proves nothing.
 */
final class ApiLogin {

	private static ?self $instance = null;

	/** User that core authenticated with an application password during this request. */
	private ?int $application_password_user_id = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	public function register(): void {
		add_filter( 'authenticate', [ $this, 'block_password_api_login' ], 31, 1 );
		add_action( 'application_password_did_authenticate', [ $this, 'remember_application_password_login' ], 10, 1 );
	}

	public function remember_application_password_login( $user ): void {
		if ( $user instanceof WP_User ) {
			$this->application_password_user_id = (int) $user->ID;
		}
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

		if ( $this->authenticated_with_application_password( $user ) ) {
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

	public function authenticated_with_application_password( WP_User $user ): bool {
		return (int) $user->ID === $this->application_password_user_id;
	}

	private function __construct() {}
}
