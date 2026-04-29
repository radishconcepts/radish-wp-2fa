<?php

declare( strict_types=1 );

namespace RadishConcepts\TwoFactor\Auth;

use RadishConcepts\TwoFactor\Roles;
use RadishConcepts\TwoFactor\Routes;
use RadishConcepts\TwoFactor\Security\Totp;
use RadishConcepts\TwoFactor\Storage\UserMeta;

/**
 * Runtime enforcement for users that already have an authenticated session
 * (cookie-based) when the admin enables enforcement on their role, or for
 * brand-new accounts that get auto-logged-in (e.g. via password reset link).
 *
 * Hooks on `template_redirect` (priority 11) so query vars are parsed and
 * Frontend\Controller::route (priority 10) has already exited on 2FA URLs.
 * Hooking earlier on `init` would have looped: get_query_var() returns empty
 * before parse_query, so the "skip on 2FA pages" guard would never fire.
 *
 * Skipped for API/cron/AJAX requests and for the 2FA pages themselves.
 */
final class Enforcement {

	private static ?self $instance = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	public function register(): void {
		add_action( 'template_redirect', [ $this, 'maybe_force_setup' ], 11 );
	}

	public function maybe_force_setup(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}

		if ( $this->is_skipped_request() ) {
			return;
		}

		$user = wp_get_current_user();

		if ( ! Roles::instance()->user_requires_2fa( $user ) ) {
			return;
		}

		if ( UserMeta::instance()->is_enrolled( $user->ID ) ) {
			return;
		}

		$token = Nonce::instance()->create(
			$user->ID,
			Nonce::MODE_SETUP,
			$this->current_url(),
			[
				'remember'       => true,
				'pending_secret' => Totp::instance()->generate_secret(),
			]
		);

		wp_safe_redirect( Routes::setup_url( $token ) );
		exit;
	}

	private function is_skipped_request(): bool {
		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			|| ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST )
			|| ( defined( 'DOING_CRON' ) && DOING_CRON )
			|| wp_doing_ajax()
		) {
			return true;
		}

		// Don't intercept on the 2FA pages themselves (would loop).
		if ( '' !== (string) get_query_var( Routes::QUERY_VAR ) ) {
			return true;
		}

		// Don't intercept on wp-login.php (logout, lostpassword, etc.).
		$pagenow = $GLOBALS['pagenow'] ?? '';
		if ( 'wp-login.php' === $pagenow || 'admin-post.php' === $pagenow ) {
			return true;
		}

		return false;
	}

	private function current_url(): string {
		$scheme = is_ssl() ? 'https://' : 'http://';
		$host   = $_SERVER['HTTP_HOST'] ?? wp_parse_url( home_url(), PHP_URL_HOST );
		$uri    = $_SERVER['REQUEST_URI'] ?? '/';

		return wp_validate_redirect( $scheme . $host . $uri, admin_url() );
	}

	private function __construct() {}
}
