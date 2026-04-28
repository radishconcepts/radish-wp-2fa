<?php

declare( strict_types=1 );

namespace RadishConcepts\TwoFactor\Tests;

use PHPUnit\Framework\TestCase;
use PragmaRX\Google2FA\Google2FA;
use RadishConcepts\TwoFactor\Security\Totp;
use WP_User;

final class TotpTest extends TestCase {

	public function test_generate_secret_returns_32_char_base32(): void {
		$secret = Totp::instance()->generate_secret();

		self::assertSame( 32, strlen( $secret ) );
		self::assertMatchesRegularExpression( '/^[A-Z2-7]+$/', $secret );
	}

	public function test_verify_accepts_current_otp(): void {
		$google = new Google2FA();
		$secret = $google->generateSecretKey( 32 );
		$code   = $google->getCurrentOtp( $secret );

		self::assertTrue( Totp::instance()->verify( $secret, $code ) );
	}

	public function test_verify_rejects_wrong_code(): void {
		$google = new Google2FA();
		$secret = $google->generateSecretKey( 32 );

		self::assertFalse( Totp::instance()->verify( $secret, '000000' ) );
	}

	public function test_verify_rejects_non_digits(): void {
		$google = new Google2FA();
		$secret = $google->generateSecretKey( 32 );

		self::assertFalse( Totp::instance()->verify( $secret, 'abcdef' ) );
		self::assertFalse( Totp::instance()->verify( $secret, '' ) );
	}

	public function test_verify_strips_whitespace(): void {
		$google = new Google2FA();
		$secret = $google->generateSecretKey( 32 );
		$code   = $google->getCurrentOtp( $secret );

		// Insert space in middle — should be normalised.
		$with_space = substr( $code, 0, 3 ) . ' ' . substr( $code, 3 );

		self::assertTrue( Totp::instance()->verify( $secret, $with_space ) );
	}

	public function test_qr_provisioning_uri_format(): void {
		$user   = new WP_User( 1, [], 'alice' );
		$secret = 'JBSWY3DPEHPK3PXP';

		$uri = Totp::instance()->get_qr_provisioning_uri( $user, $secret );

		self::assertStringStartsWith( 'otpauth://totp/', $uri );
		self::assertStringContainsString( 'secret=' . $secret, $uri );
		self::assertStringContainsString( 'alice', $uri );
	}
}
