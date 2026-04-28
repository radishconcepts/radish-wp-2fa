<?php

declare( strict_types=1 );

namespace RadishConcepts\TwoFactor\Frontend;

use RadishConcepts\TwoFactor\Activation;
use RadishConcepts\TwoFactor\Auth\Nonce;
use RadishConcepts\TwoFactor\Routes;
use RadishConcepts\TwoFactor\Security\BackupCodes;
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

	private function render_setup( string $token, array $payload, WP_User $user, ?string $error = null ): void {
		if ( Nonce::MODE_BACKUP === ( $payload['mode'] ?? '' ) ) {
			$this->render_backup_codes( $token, $payload );

			return;
		}

		$secret = (string) ( $payload['pending_secret'] ?? '' );
		if ( '' === $secret ) {
			$this->render_expired();

			return;
		}

		$qr_uri = Totp::instance()->get_qr_provisioning_uri( $user, $secret );

		$this->render_template( 'setup.php', [
			'token'   => $token,
			'user'    => $user,
			'secret'  => $secret,
			'qr_svg'  => Qr::svg( $qr_uri ),
			'error'   => $error,
		] );
	}

	private function handle_setup_post( string $token, array $payload, WP_User $user ): void {
		if ( Nonce::MODE_BACKUP === ( $payload['mode'] ?? '' ) ) {
			$this->complete_login( $token, $payload, $user );

			return;
		}

		$secret = (string) ( $payload['pending_secret'] ?? '' );
		$code   = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '';

		if ( '' === $secret || ! Totp::instance()->verify( $secret, $code ) ) {
			$this->render_setup( $token, $payload, $user, __( 'The code is incorrect. Please try again.', 'radish-2fa' ) );

			return;
		}

		$plain_codes = BackupCodes::instance()->generate();

		UserMeta::instance()->set_secret( $user->ID, $secret );
		UserMeta::instance()->set_backup_code_hashes( $user->ID, BackupCodes::instance()->hash_all( $plain_codes ) );

		Nonce::instance()->update( $token, [
			'user_id'      => $user->ID,
			'mode'         => Nonce::MODE_BACKUP,
			'redirect_to'  => $payload['redirect_to'] ?? admin_url(),
			'remember'     => ! empty( $payload['remember'] ),
			'plain_codes'  => $plain_codes,
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

	private function render_challenge( string $token, array $payload, WP_User $user, ?string $error = null ): void {
		$this->render_template( 'challenge.php', [
			'token' => $token,
			'user'  => $user,
			'error' => $error,
		] );
	}

	private function handle_challenge_post( string $token, array $payload, WP_User $user ): void {
		$code = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '';

		if ( $this->verify_code_for_user( $user, $code ) ) {
			$this->complete_login( $token, $payload, $user );

			return;
		}

		$this->render_challenge( $token, $payload, $user, __( 'The code is incorrect. Please try again or use a backup code.', 'radish-2fa' ) );
	}

	private function verify_code_for_user( WP_User $user, string $code ): bool {
		$normalized = trim( $code );
		if ( '' === $normalized ) {
			return false;
		}

		$digits_only = preg_replace( '/\s+/', '', $normalized ) ?? '';
		if ( ctype_digit( $digits_only ) && 6 === strlen( $digits_only ) ) {
			$secret = UserMeta::instance()->get_secret( $user->ID );
			if ( null !== $secret && Totp::instance()->verify( $secret, $digits_only ) ) {
				return true;
			}
		}

		$hashes = UserMeta::instance()->get_backup_code_hashes( $user->ID );
		$match  = BackupCodes::instance()->find_match( $normalized, $hashes );
		if ( null === $match ) {
			return false;
		}

		UserMeta::instance()->consume_backup_code( $user->ID, $match );

		return true;
	}

	private function complete_login( string $token, array $payload, WP_User $user ): void {
		Nonce::instance()->consume( $token );
		UserMeta::instance()->mark_used( $user->ID );

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

		$vars['css_url']  = RADISH_2FA_URL . 'assets/css/radish-2fa.css?v=' . RADISH_2FA_VERSION;
		$vars['site_name'] = get_bloginfo( 'name' );

		( static function ( string $__template, array $__vars ): void {
			extract( $__vars, EXTR_SKIP );
			require $__template;
		} )( $template, $vars );
	}

	private function __construct() {}
}
