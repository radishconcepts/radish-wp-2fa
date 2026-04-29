<?php

declare( strict_types=1 );

namespace RadishConcepts\TwoFactor\Tests;

use PHPUnit\Framework\TestCase;
use RadishConcepts\TwoFactor\Security\EmailOtp;

final class EmailOtpTest extends TestCase {

	public function test_generate_returns_six_digit_code(): void {
		for ( $i = 0; $i < 20; $i++ ) {
			$code = EmailOtp::instance()->generate();
			self::assertMatchesRegularExpression( '/^\d{6}$/', $code );
		}
	}

	public function test_hash_and_verify_round_trip(): void {
		$code   = EmailOtp::instance()->generate();
		$hash   = EmailOtp::instance()->hash( $code );
		$expiry = time() + 60;

		self::assertTrue( EmailOtp::instance()->verify( $code, $hash, $expiry ) );
	}

	public function test_verify_strips_whitespace(): void {
		$code   = '123456';
		$hash   = EmailOtp::instance()->hash( $code );
		$expiry = time() + 60;

		self::assertTrue( EmailOtp::instance()->verify( ' 123 456 ', $hash, $expiry ) );
	}

	public function test_verify_rejects_wrong_code(): void {
		$hash   = EmailOtp::instance()->hash( '123456' );
		$expiry = time() + 60;

		self::assertFalse( EmailOtp::instance()->verify( '654321', $hash, $expiry ) );
	}

	public function test_verify_rejects_non_numeric_or_wrong_length(): void {
		$hash   = EmailOtp::instance()->hash( '123456' );
		$expiry = time() + 60;

		self::assertFalse( EmailOtp::instance()->verify( '12345', $hash, $expiry ) );
		self::assertFalse( EmailOtp::instance()->verify( 'abcdef', $hash, $expiry ) );
		self::assertFalse( EmailOtp::instance()->verify( '', $hash, $expiry ) );
	}

	public function test_verify_rejects_expired_code(): void {
		$code   = '123456';
		$hash   = EmailOtp::instance()->hash( $code );
		$expiry = time() - 1;

		self::assertFalse( EmailOtp::instance()->verify( $code, $hash, $expiry ) );
	}

	public function test_verify_rejects_empty_hash(): void {
		self::assertFalse( EmailOtp::instance()->verify( '123456', '', time() + 60 ) );
	}
}
