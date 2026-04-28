<?php

declare( strict_types=1 );

namespace RadishConcepts\TwoFactor\Security;

use PragmaRX\Google2FA\Google2FA;
use WP_User;

final class Totp {

	private const WINDOW = 1;

	private static ?self $instance = null;

	private Google2FA $google2fa;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	public function generate_secret(): string {
		return $this->google2fa->generateSecretKey( 32 );
	}

	public function verify( string $secret, string $code ): bool {
		$normalized = preg_replace( '/\s+/', '', $code ) ?? '';
		if ( '' === $normalized || ! ctype_digit( $normalized ) ) {
			return false;
		}

		try {
			return (bool) $this->google2fa->verifyKey( $secret, $normalized, self::WINDOW );
		} catch ( \Throwable ) {
			return false;
		}
	}

	public function get_qr_provisioning_uri( WP_User $user, string $secret ): string {
		$issuer = $this->get_issuer();
		$label  = $this->get_account_label( $user );

		return $this->google2fa->getQRCodeUrl( $issuer, $label, $secret );
	}

	private function get_issuer(): string {
		$name = is_multisite()
			? get_network()->site_name ?? get_bloginfo( 'name' )
			: get_bloginfo( 'name' );

		$name = (string) apply_filters( 'radish_2fa_totp_issuer', $name );

		return preg_replace( '/[^A-Za-z0-9 _.\-]/', '', $name ) ?: 'WordPress';
	}

	private function get_account_label( WP_User $user ): string {
		$host = wp_parse_url( home_url(), PHP_URL_HOST ) ?: 'site';

		return sprintf( '%s@%s', $user->user_login, $host );
	}

	private function __construct() {
		$this->google2fa = new Google2FA();
	}
}
