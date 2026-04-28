<?php

declare( strict_types=1 );

namespace RadishConcepts\TwoFactor\Auth;

use RadishConcepts\TwoFactor\Roles;
use RadishConcepts\TwoFactor\Routes;
use RadishConcepts\TwoFactor\Security\Totp;
use RadishConcepts\TwoFactor\Storage\UserMeta;
use WP_Session_Tokens;
use WP_User;

/**
 * Hooks into the authentication chain to suspend login when 2FA is required:
 * 1. authenticate (priority 30): suppress auth cookie for users that need 2FA.
 * 2. auth_cookie/logged_in_cookie filters: capture the session token wp_set_auth_cookie() created.
 * 3. wp_login (PHP_INT_MAX): destroy the just-created session, redirect to /2fa/{setup|challenge}.
 */
final class LoginInterceptor {

	private static ?self $instance = null;

	/** @var string[] */
	private array $captured_tokens = [];

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	public function register(): void {
		add_filter( 'authenticate', [ $this, 'suspend_auth_cookie_if_needed' ], 30, 1 );
		add_filter( 'auth_cookie', [ $this, 'capture_session_token' ], 10, 1 );
		add_filter( 'logged_in_cookie', [ $this, 'capture_session_token' ], 10, 1 );
		add_action( 'wp_login', [ $this, 'redirect_to_2fa' ], PHP_INT_MAX, 2 );
	}

	public function suspend_auth_cookie_if_needed( $user ) {
		if ( ! $user instanceof WP_User ) {
			return $user;
		}

		if ( ! Roles::instance()->user_requires_2fa( $user ) ) {
			return $user;
		}

		add_filter( 'send_auth_cookies', '__return_false', PHP_INT_MAX );

		return $user;
	}

	/**
	 * WP cookie format: `username|expiration|token|mac`. We grab the token so we
	 * can destroy the session that wp_set_auth_cookie() just registered.
	 */
	public function capture_session_token( string $cookie ): string {
		$parts = explode( '|', $cookie );
		if ( 4 === count( $parts ) ) {
			$this->captured_tokens[] = $parts[2];
		}

		return $cookie;
	}

	public function redirect_to_2fa( $user_login, $user ): void {
		if ( ! $user instanceof WP_User ) {
			return;
		}

		if ( ! Roles::instance()->user_requires_2fa( $user ) ) {
			return;
		}

		$this->destroy_captured_sessions( $user );
		wp_clear_auth_cookie();

		$mode = UserMeta::instance()->is_enrolled( $user->ID ) ? Nonce::MODE_CHALLENGE : Nonce::MODE_SETUP;

		$extra = [ 'remember' => ! empty( $_POST['rememberme'] ) ];
		if ( Nonce::MODE_SETUP === $mode ) {
			$extra['pending_secret'] = Totp::instance()->generate_secret();
		}

		$token = Nonce::instance()->create( $user->ID, $mode, $this->resolve_redirect_to(), $extra );

		$url = Nonce::MODE_SETUP === $mode
			? Routes::setup_url( $token )
			: Routes::challenge_url( $token );

		wp_safe_redirect( $url );
		exit;
	}

	private function destroy_captured_sessions( WP_User $user ): void {
		if ( empty( $this->captured_tokens ) ) {
			return;
		}

		$manager = WP_Session_Tokens::get_instance( $user->ID );
		foreach ( $this->captured_tokens as $token ) {
			$manager->destroy( $token );
		}

		$this->captured_tokens = [];
	}

	private function resolve_redirect_to(): string {
		$requested = isset( $_REQUEST['redirect_to'] ) ? (string) wp_unslash( $_REQUEST['redirect_to'] ) : '';

		return wp_validate_redirect( $requested, admin_url() );
	}

	private function __construct() {}
}
