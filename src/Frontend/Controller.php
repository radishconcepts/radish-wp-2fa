<?php

declare( strict_types=1 );

namespace RadishConcepts\TwoFactor\Frontend;

use RadishConcepts\TwoFactor\Activation;
use RadishConcepts\TwoFactor\Admin\Settings;
use RadishConcepts\TwoFactor\Auth\Nonce;
use RadishConcepts\TwoFactor\Methods\Method;
use RadishConcepts\TwoFactor\Routes;
use RadishConcepts\TwoFactor\Security\BackupCodes;
use RadishConcepts\TwoFactor\Security\EmailMailer;
use RadishConcepts\TwoFactor\Security\EmailOtp;
use RadishConcepts\TwoFactor\Security\EmailRateLimit;
use RadishConcepts\TwoFactor\Security\Qr;
use RadishConcepts\TwoFactor\Security\Totp;
use RadishConcepts\TwoFactor\Storage\UserMeta;
use WP_User;

final class Controller {

	private static ?self $instance = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	public function register(): void {
		add_action( 'init', [ $this, 'register_rewrites' ] );
		add_action( 'init', [ $this, 'maybe_flush_rewrites' ], 99 );
		add_filter( 'query_vars', [ $this, 'register_query_vars' ] );
		add_action( 'template_redirect', [ $this, 'route' ] );
	}

	public function register_rewrites(): void {
		add_rewrite_rule(
			'^2fa/(setup|challenge)/?$',
			'index.php?' . Routes::QUERY_VAR . '=$matches[1]',
			'top'
		);
	}

	public function maybe_flush_rewrites(): void {
		if ( get_option( Activation::REWRITE_VERSION_KEY ) === RADISH_2FA_VERSION ) {
			return;
		}

		flush_rewrite_rules( false );
		update_option( Activation::REWRITE_VERSION_KEY, RADISH_2FA_VERSION, false );
	}

	public function register_query_vars( array $vars ): array {
		$vars[] = Routes::QUERY_VAR;

		return $vars;
	}

	public function route(): void {
		$action = (string) get_query_var( Routes::QUERY_VAR );
		if ( '' === $action ) {
			return;
		}

		nocache_headers();

		$token   = isset( $_GET[ Routes::TOKEN_PARAM ] ) ? sanitize_text_field( wp_unslash( $_GET[ Routes::TOKEN_PARAM ] ) ) : '';
		$payload = Nonce::instance()->peek( $token );

		if ( null === $payload ) {
			$this->render_expired();
			exit;
		}

		$user = get_userdata( (int) $payload['user_id'] );
		if ( ! $user instanceof WP_User ) {
			$this->render_expired();
			exit;
		}

		$is_post = 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? 'GET' );

		switch ( $action ) {
			case Routes::ACTION_SETUP:
				$is_post ? $this->handle_setup_post( $token, $payload, $user ) : $this->render_setup( $token, $payload, $user );
				break;

			case Routes::ACTION_CHALLENGE:
				$is_post ? $this->handle_challenge_post( $token, $payload, $user ) : $this->render_challenge( $token, $payload, $user );
				break;

			default:
				$this->render_expired();
		}

		exit;
	}

	// ──────────────────────────────────────────────────────────────────────
	//  SETUP
	// ──────────────────────────────────────────────────────────────────────

	private function render_setup( string $token, array $payload, WP_User $user, ?string $error = null, ?string $info = null ): void {
		if ( Nonce::MODE_BACKUP === ( $payload['mode'] ?? '' ) ) {
			$this->render_backup_codes( $token, $payload );

			return;
		}

		if ( ! empty( $_GET['reset_method'] ) ) {
			$payload = $this->clear_chosen_method( $token, $payload );
		}

		$enabled_methods = Settings::instance()->enabled_methods();
		$chosen          = $this->normalize_chosen_method( $payload['chosen_method'] ?? null, $enabled_methods );

		if ( null === $chosen ) {
			if ( count( $enabled_methods ) === 1 ) {
				// Only one method available: skip the chooser and resolve it on the fly.
				$payload = $this->set_chosen_method( $token, $payload, $enabled_methods[0] );
				$chosen  = $enabled_methods[0];
			} else {
				$this->render_setup_chooser( $token, $payload, $user, $enabled_methods, $error );

				return;
			}
		}

		if ( Method::EMAIL === $chosen ) {
			$payload = $this->ensure_email_code( $token, $payload, $user );
			$this->render_setup_email( $token, $payload, $user, $error, $info );

			return;
		}

		$this->render_setup_totp( $token, $payload, $user, $error );
	}

	private function render_setup_chooser( string $token, array $payload, WP_User $user, array $methods, ?string $error ): void {
		$this->render_template( 'setup-method-chooser.php', [
			'token'   => $token,
			'user'    => $user,
			'methods' => $methods,
			'error'   => $error,
		] );
	}

	private function render_setup_totp( string $token, array $payload, WP_User $user, ?string $error ): void {
		$secret = (string) ( $payload['pending_secret'] ?? '' );
		if ( '' === $secret ) {
			// Lazy-generate a TOTP secret only once the user has committed to TOTP.
			$secret  = Totp::instance()->generate_secret();
			$payload = array_merge( $payload, [ 'pending_secret' => $secret ] );
			Nonce::instance()->update( $token, $payload );
		}

		$qr_uri = Totp::instance()->get_qr_provisioning_uri( $user, $secret );

		$this->render_template( 'setup.php', [
			'token'  => $token,
			'user'   => $user,
			'secret' => $secret,
			'qr_svg' => Qr::svg( $qr_uri ),
			'error'  => $error,
		] );
	}

	private function render_setup_email( string $token, array $payload, WP_User $user, ?string $error, ?string $info ): void {
		$enabled_methods = Settings::instance()->enabled_methods();

		$this->render_template( 'setup-email.php', [
			'token'            => $token,
			'user'             => $user,
			'masked_email'     => $this->mask_email( (string) $user->user_email ),
			'error'            => $error,
			'info'             => $info,
			'cooldown_seconds' => EmailRateLimit::instance()->seconds_until_next_send( $user->ID ),
			'can_choose_again' => count( $enabled_methods ) > 1,
			'chooser_url'      => add_query_arg(
				[ Routes::TOKEN_PARAM => $token, 'reset_method' => '1' ],
				home_url( '/' . Routes::setup_path() )
			),
		] );
	}

	private function handle_setup_post( string $token, array $payload, WP_User $user ): void {
		if ( Nonce::MODE_BACKUP === ( $payload['mode'] ?? '' ) ) {
			$this->complete_login( $token, $payload, $user );

			return;
		}

		$enabled_methods = Settings::instance()->enabled_methods();
		$chosen          = $this->normalize_chosen_method( $payload['chosen_method'] ?? null, $enabled_methods );

		// Chooser POST: validate the picked method, store it, redraw the setup screen.
		if ( null === $chosen ) {
			$picked = isset( $_POST['method'] ) ? sanitize_key( wp_unslash( $_POST['method'] ) ) : '';
			if ( ! Method::is_valid( $picked ) || ! in_array( $picked, $enabled_methods, true ) ) {
				$this->render_setup_chooser( $token, $payload, $user, $enabled_methods, __( 'Please choose a verification method.', 'radish-2fa' ) );

				return;
			}
			$payload = $this->set_chosen_method( $token, $payload, $picked );
			$this->render_setup( $token, $payload, $user );

			return;
		}

		if ( Method::EMAIL === $chosen ) {
			$this->handle_setup_email_post( $token, $payload, $user );

			return;
		}

		$this->handle_setup_totp_post( $token, $payload, $user );
	}

	private function handle_setup_totp_post( string $token, array $payload, WP_User $user ): void {
		$secret = (string) ( $payload['pending_secret'] ?? '' );
		$code   = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '';

		if ( '' === $secret || ! Totp::instance()->verify( $secret, $code ) ) {
			$this->render_setup_totp( $token, $payload, $user, __( 'The code is incorrect. Please try again.', 'radish-2fa' ) );

			return;
		}

		$plain_codes = BackupCodes::instance()->generate();

		UserMeta::instance()->set_secret( $user->ID, $secret );
		UserMeta::instance()->set_backup_code_hashes( $user->ID, BackupCodes::instance()->hash_all( $plain_codes ) );

		$this->advance_to_backup_codes( $token, $payload, $user, $plain_codes );
	}

	private function handle_setup_email_post( string $token, array $payload, WP_User $user ): void {
		$action = isset( $_POST['r2fa_action'] ) ? sanitize_key( wp_unslash( $_POST['r2fa_action'] ) ) : 'verify';

		if ( 'resend' === $action ) {
			$payload = $this->resend_email_code( $token, $payload, $user );
			$info    = ! empty( $payload['email_code_hash'] )
				? __( 'A new code has been sent.', 'radish-2fa' )
				: null;
			$error   = empty( $payload['email_code_hash'] )
				? __( 'You have requested too many codes. Please wait a bit and try again.', 'radish-2fa' )
				: null;

			$this->render_setup_email( $token, $payload, $user, $error, $info );

			return;
		}

		$code   = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '';
		$hash   = (string) ( $payload['email_code_hash'] ?? '' );
		$expiry = (int) ( $payload['email_code_expires_at'] ?? 0 );

		if ( ! EmailOtp::instance()->verify( $code, $hash, $expiry ) ) {
			$this->render_setup_email( $token, $payload, $user, __( 'The code is incorrect or expired. Please try again.', 'radish-2fa' ), null );

			return;
		}

		$plain_codes = BackupCodes::instance()->generate();

		UserMeta::instance()->enroll_email( $user->ID );
		UserMeta::instance()->set_backup_code_hashes( $user->ID, BackupCodes::instance()->hash_all( $plain_codes ) );

		$this->advance_to_backup_codes( $token, $payload, $user, $plain_codes );
	}

	private function advance_to_backup_codes( string $token, array $payload, WP_User $user, array $plain_codes ): void {
		Nonce::instance()->update( $token, [
			'user_id'     => $user->ID,
			'mode'        => Nonce::MODE_BACKUP,
			'redirect_to' => $payload['redirect_to'] ?? admin_url(),
			'remember'    => ! empty( $payload['remember'] ),
			'plain_codes' => $plain_codes,
		] );

		$this->render_backup_codes( $token, [
			'plain_codes' => $plain_codes,
			'redirect_to' => $payload['redirect_to'] ?? admin_url(),
		] );
	}

	private function render_backup_codes( string $token, array $payload ): void {
		$this->render_template( 'backup-codes.php', [
			'token'       => $token,
			'plain_codes' => (array) ( $payload['plain_codes'] ?? [] ),
		] );
	}

	// ──────────────────────────────────────────────────────────────────────
	//  CHALLENGE
	// ──────────────────────────────────────────────────────────────────────

	private function render_challenge( string $token, array $payload, WP_User $user, ?string $error = null, ?string $info = null ): void {
		$method = UserMeta::instance()->get_method( $user->ID );

		if ( Method::EMAIL === $method ) {
			$payload = $this->ensure_email_code( $token, $payload, $user );
			$this->render_challenge_email( $token, $payload, $user, $error, $info );

			return;
		}

		// TOTP (default) and back-compat for users without an explicit method.
		$this->render_challenge_totp( $token, $payload, $user, $error );
	}

	private function render_challenge_totp( string $token, array $payload, WP_User $user, ?string $error ): void {
		$this->render_template( 'challenge.php', [
			'token' => $token,
			'user'  => $user,
			'error' => $error,
		] );
	}

	private function render_challenge_email( string $token, array $payload, WP_User $user, ?string $error, ?string $info ): void {
		$this->render_template( 'challenge-email.php', [
			'token'            => $token,
			'user'             => $user,
			'masked_email'     => $this->mask_email( (string) $user->user_email ),
			'error'            => $error,
			'info'             => $info,
			'cooldown_seconds' => EmailRateLimit::instance()->seconds_until_next_send( $user->ID ),
		] );
	}

	private function handle_challenge_post( string $token, array $payload, WP_User $user ): void {
		$method = UserMeta::instance()->get_method( $user->ID );

		if ( Method::EMAIL === $method ) {
			$this->handle_challenge_email_post( $token, $payload, $user );

			return;
		}

		$code = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '';

		if ( $this->verify_challenge_code( $user, $code, $payload ) ) {
			$this->complete_login( $token, $payload, $user );

			return;
		}

		$this->render_challenge_totp(
			$token,
			$payload,
			$user,
			__( 'The code is incorrect. Please try again or use a backup code.', 'radish-2fa' )
		);
	}

	private function handle_challenge_email_post( string $token, array $payload, WP_User $user ): void {
		$action = isset( $_POST['r2fa_action'] ) ? sanitize_key( wp_unslash( $_POST['r2fa_action'] ) ) : 'verify';

		if ( 'resend' === $action ) {
			$payload = $this->resend_email_code( $token, $payload, $user );
			$info    = ! empty( $payload['email_code_hash'] )
				? __( 'A new code has been sent.', 'radish-2fa' )
				: null;
			$error   = empty( $payload['email_code_hash'] )
				? __( 'You have requested too many codes. Please wait a bit and try again.', 'radish-2fa' )
				: null;

			$this->render_challenge_email( $token, $payload, $user, $error, $info );

			return;
		}

		$code = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '';

		if ( $this->verify_challenge_code( $user, $code, $payload ) ) {
			$this->complete_login( $token, $payload, $user );

			return;
		}

		$this->render_challenge_email(
			$token,
			$payload,
			$user,
			__( 'The code is incorrect or expired. Please try again or use a backup code.', 'radish-2fa' ),
			null
		);
	}

	/**
	 * Verifies a submitted challenge code against the user's enrolled method,
	 * with backup codes as a universal fallback.
	 */
	private function verify_challenge_code( WP_User $user, string $code, array $payload ): bool {
		$normalized = trim( $code );
		if ( '' === $normalized ) {
			return false;
		}

		$method      = UserMeta::instance()->get_method( $user->ID );
		$digits_only = preg_replace( '/\s+/', '', $normalized ) ?? '';

		if ( Method::EMAIL === $method ) {
			$hash   = (string) ( $payload['email_code_hash'] ?? '' );
			$expiry = (int) ( $payload['email_code_expires_at'] ?? 0 );
			if ( EmailOtp::instance()->verify( $digits_only, $hash, $expiry ) ) {
				return true;
			}
		} elseif ( ctype_digit( $digits_only ) && 6 === strlen( $digits_only ) ) {
			$secret = UserMeta::instance()->get_secret( $user->ID );
			if ( null !== $secret && Totp::instance()->verify( $secret, $digits_only ) ) {
				return true;
			}
		}

		// Backup-code fallback works regardless of method.
		$hashes = UserMeta::instance()->get_backup_code_hashes( $user->ID );
		$match  = BackupCodes::instance()->find_match( $normalized, $hashes );
		if ( null === $match ) {
			return false;
		}

		UserMeta::instance()->consume_backup_code( $user->ID, $match );

		return true;
	}

	// ──────────────────────────────────────────────────────────────────────
	//  EMAIL CODE LIFECYCLE
	// ──────────────────────────────────────────────────────────────────────

	/**
	 * Make sure the nonce holds a valid (unexpired) email code; send a new one
	 * on first arrival or if the previous one has expired.
	 */
	private function ensure_email_code( string $token, array $payload, WP_User $user ): array {
		$hash   = (string) ( $payload['email_code_hash'] ?? '' );
		$expiry = (int) ( $payload['email_code_expires_at'] ?? 0 );

		if ( '' !== $hash && $expiry > time() ) {
			return $payload;
		}

		return $this->send_email_code( $token, $payload, $user, $payload );
	}

	/**
	 * Send a new code, respecting rate limits. Returns the (possibly unchanged)
	 * payload after the attempt.
	 */
	private function resend_email_code( string $token, array $payload, WP_User $user ): array {
		return $this->send_email_code( $token, $payload, $user, $payload );
	}

	private function send_email_code( string $token, array $payload, WP_User $user, array $base ): array {
		if ( ! EmailRateLimit::instance()->can_send( $user->ID ) ) {
			return $base;
		}

		$plain  = EmailOtp::instance()->generate();
		$hash   = EmailOtp::instance()->hash( $plain );
		$expiry = time() + EmailOtp::TTL;

		if ( ! EmailMailer::instance()->send( $user, $plain ) ) {
			return $base;
		}

		EmailRateLimit::instance()->record_send( $user->ID );

		$updated = array_merge( $base, [
			'email_code_hash'       => $hash,
			'email_code_expires_at' => $expiry,
		] );
		Nonce::instance()->update( $token, $updated );

		return $updated;
	}

	// ──────────────────────────────────────────────────────────────────────
	//  HELPERS
	// ──────────────────────────────────────────────────────────────────────

	private function normalize_chosen_method( $stored, array $enabled ): ?string {
		if ( ! is_string( $stored ) || ! Method::is_valid( $stored ) ) {
			return null;
		}
		if ( ! in_array( $stored, $enabled, true ) ) {
			return null;
		}

		return $stored;
	}

	private function set_chosen_method( string $token, array $payload, string $method ): array {
		$updated = array_merge( $payload, [ 'chosen_method' => $method ] );
		Nonce::instance()->update( $token, $updated );

		return $updated;
	}

	private function clear_chosen_method( string $token, array $payload ): array {
		unset( $payload['chosen_method'], $payload['pending_secret'], $payload['email_code_hash'], $payload['email_code_expires_at'] );
		Nonce::instance()->update( $token, $payload );

		return $payload;
	}

	private function complete_login( string $token, array $payload, WP_User $user ): void {
		Nonce::instance()->consume( $token );
		UserMeta::instance()->mark_used( $user->ID );
		// Successful verification proves it's really this user — drop the email
		// rate-limit so the next sign-in can immediately request a fresh code.
		EmailRateLimit::instance()->clear( $user->ID );

		wp_set_auth_cookie( $user->ID, ! empty( $payload['remember'] ), is_ssl() );

		$redirect_to = isset( $payload['redirect_to'] ) ? wp_validate_redirect( (string) $payload['redirect_to'], admin_url() ) : admin_url();

		wp_safe_redirect( $redirect_to );
		exit;
	}

	private function render_expired(): void {
		status_header( 410 );
		$this->render_template( 'expired.php', [] );
	}

	private function render_template( string $name, array $vars ): void {
		$theme_template = locate_template( [ 'radish-2fa/' . $name ] );
		$template       = '' !== $theme_template ? $theme_template : RADISH_2FA_DIR . 'templates/' . $name;

		if ( ! is_readable( $template ) ) {
			wp_die( esc_html__( 'Template missing.', 'radish-2fa' ), 500 );
		}

		$vars['css_url']   = RADISH_2FA_URL . 'assets/css/radish-2fa.css?v=' . RADISH_2FA_VERSION;
		$vars['site_name'] = get_bloginfo( 'name' );

		( static function ( string $__template, array $__vars ): void {
			extract( $__vars, EXTR_SKIP );
			require $__template;
		} )( $template, $vars );
	}

	/**
	 * Mask an email address for display: a******@example.com.
	 */
	private function mask_email( string $email ): string {
		if ( ! is_email( $email ) ) {
			return $email;
		}
		[ $local, $domain ] = explode( '@', $email, 2 );

		$visible = mb_substr( $local, 0, 1 );
		$masked  = $visible . str_repeat( '*', max( 1, mb_strlen( $local ) - 1 ) );

		return $masked . '@' . $domain;
	}

	private function __construct() {}
}
