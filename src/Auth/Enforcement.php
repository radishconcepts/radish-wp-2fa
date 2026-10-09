<?php

declare( strict_types=1 );

namespace RadishConcepts\TwoFactor\Auth;

use RadishConcepts\TwoFactor\Roles;
use RadishConcepts\TwoFactor\Routes;
use RadishConcepts\TwoFactor\Storage\UserMeta;
use WP_Error;
use WP_User;

/**
 * Runtime enforcement for users that already have an authenticated session
 * (cookie-based) when the admin enables enforcement on their role, for
 * brand-new accounts that get auto-logged-in (e.g. via password reset link),
 * and for any session that was created without passing 2FA:
 *
 * - Not enrolled yet → sent to setup.
 * - Enrolled, but the session lacks SessionGuard's mark → the session is
 *   destroyed and the user is sent to the challenge.
 *
 * Hooks on `template_redirect` (priority 11) so query vars are parsed and
 * Frontend\Controller::route (priority 10) has already exited on 2FA URLs.
 * Hooking earlier on `init` would have looped: get_query_var() returns empty
 * before parse_query, so the "skip on 2FA pages" guard would never fire.
 * Also hooks on `admin_init`, so wp-admin is covered as well. AJAX and
 * admin-post requests from an unverified session get a 401 (they can't be
 * redirected), and so do cookie-authenticated REST requests.
 *
 * Skipped for XML-RPC/cron requests, wp-login.php and the 2FA pages themselves.
 */
final class Enforcement {

	private static ?self $instance = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	public function register(): void {
		add_action( 'template_redirect', [ $this, 'enforce' ], 11 );
		add_action( 'admin_init', [ $this, 'enforce' ] );
		// After core's cookie and nonce check (priority 100).
		add_filter( 'rest_authentication_errors', [ $this, 'block_unverified_rest' ], 101 );
	}

	public function enforce(): void {
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

		// AJAX and admin-post can't be redirected to a 2FA page. Refuse them,
		// so an unverified session can't fetch a REST nonce or submit actions.
		if ( $this->is_background_request() ) {
			if ( $this->session_needs_challenge( $user ) ) {
				$this->deny_background_request();
			}

			return;
		}

		if ( ! UserMeta::instance()->is_enrolled( $user->ID ) ) {
			$this->force_setup( $user );
		}

		if ( $this->session_needs_challenge( $user ) ) {
			$this->force_challenge( $user );
		}
	}

	/**
	 * Refuse cookie-authenticated REST requests from a session that never
	 * passed 2FA. Application passwords and other non-cookie logins are left
	 * alone: they have no session to mark.
	 *
	 * @param \WP_Error|true|null $result
	 *
	 * @return \WP_Error|true|null
	 */
	public function block_unverified_rest( $result ) {
		if ( is_wp_error( $result ) || ! is_user_logged_in() ) {
			return $result;
		}

		$user = wp_get_current_user();

		if ( ! SessionGuard::instance()->has_validated_cookie( $user )
			|| ApiLogin::instance()->authenticated_with_application_password( $user )
			|| ! Roles::instance()->user_requires_2fa( $user )
			|| ! $this->session_needs_challenge( $user )
		) {
			return $result;
		}

		return new WP_Error(
			'radish_2fa_verification_required',
			__( 'This session has not passed two-factor authentication. Reload the page to verify your sign-in.', 'radish-2fa' ),
			[ 'status' => 401 ]
		);
	}

	private function force_setup( WP_User $user ): void {
		$token = Nonce::instance()->create(
			$user->ID,
			Nonce::MODE_SETUP,
			$this->current_url(),
			[
				'remember' => true,
			]
		);

		wp_safe_redirect( Routes::setup_url( $token ) );
		exit;
	}

	/**
	 * End a session that never passed 2FA and ask for the code again. The
	 * challenge sets a fresh, marked session on success.
	 *
	 * A submitted form can't survive the round trip, so instead of silently
	 * redirecting a POST (and losing its data without a word) the user gets a
	 * page that says so and links to the challenge.
	 */
	private function force_challenge( WP_User $user ): void {
		$session     = SessionGuard::instance()->current_session( $user ) ?? [];
		$is_get      = in_array( $_SERVER['REQUEST_METHOD'] ?? 'GET', [ 'GET', 'HEAD' ], true );
		$redirect_to = $is_get ? $this->current_url() : (string) ( wp_get_referer() ?: admin_url() );

		SessionGuard::instance()->destroy_current_session( $user );

		$token = Nonce::instance()->create(
			$user->ID,
			Nonce::MODE_CHALLENGE,
			$redirect_to,
			[
				'remember' => SessionGuard::was_remembered( $session ),
			]
		);

		if ( $is_get ) {
			wp_safe_redirect( Routes::challenge_url( $token ) );
			exit;
		}

		wp_die(
			esc_html__( 'Your changes were not saved. For your security, confirm your sign-in with your verification code first, then submit the form again.', 'radish-2fa' ),
			esc_html__( 'Verification required', 'radish-2fa' ),
			[
				'response'  => 401,
				'link_url'  => esc_url_raw( Routes::challenge_url( $token ) ),
				'link_text' => esc_html__( 'Enter verification code', 'radish-2fa' ),
			]
		);
	}

	private function deny_background_request(): void {
		$message = __( 'This session has not passed two-factor authentication. Reload the page to verify your sign-in.', 'radish-2fa' );

		if ( wp_doing_ajax() ) {
			wp_send_json_error(
				[
					'code'    => 'radish_2fa_verification_required',
					'message' => $message,
				],
				401
			);
		}

		wp_die(
			esc_html( $message ),
			esc_html__( 'Verification required', 'radish-2fa' ),
			[
				'response'  => 401,
				'link_url'  => esc_url_raw( admin_url() ),
				'link_text' => esc_html__( 'Enter verification code', 'radish-2fa' ),
			]
		);
	}

	/**
	 * Whether the current request's session lacks the 2FA mark and must get it.
	 */
	private function session_needs_challenge( WP_User $user ): bool {
		if ( SessionGuard::instance()->request_is_verified( $user ) ) {
			return false;
		}

		/**
		 * Whether a logged-in user who requires 2FA must have a session that
		 * passed the 2FA step. Return false for sessions that another trusted
		 * mechanism creates on purpose (e.g. a user-switching plugin).
		 *
		 * @param bool                     $required Default true.
		 * @param WP_User                  $user     Current user.
		 * @param array<string,mixed>|null $session  The request's session, if any.
		 */
		return (bool) apply_filters(
			'radish_2fa_require_verified_session',
			true,
			$user,
			SessionGuard::instance()->current_session( $user )
		);
	}

	private function is_skipped_request(): bool {
		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			|| ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST )
			|| ( defined( 'DOING_CRON' ) && DOING_CRON )
		) {
			return true;
		}

		// Don't intercept on the 2FA pages themselves (would loop).
		if ( '' !== (string) get_query_var( Routes::QUERY_VAR ) ) {
			return true;
		}

		// Don't intercept on wp-login.php (logout, lostpassword, etc.).
		return 'wp-login.php' === ( $GLOBALS['pagenow'] ?? '' );
	}

	private function is_background_request(): bool {
		return wp_doing_ajax() || 'admin-post.php' === ( $GLOBALS['pagenow'] ?? '' );
	}

	private function current_url(): string {
		$scheme = is_ssl() ? 'https://' : 'http://';
		$host   = $_SERVER['HTTP_HOST'] ?? wp_parse_url( home_url(), PHP_URL_HOST );
		$uri    = $_SERVER['REQUEST_URI'] ?? '/';

		return wp_validate_redirect( $scheme . $host . $uri, admin_url() );
	}

	private function __construct() {}
}
