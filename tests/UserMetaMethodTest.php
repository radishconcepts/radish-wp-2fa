<?php

declare( strict_types=1 );

namespace RadishConcepts\TwoFactor\Tests;

use PHPUnit\Framework\TestCase;
use RadishConcepts\TwoFactor\Methods\Method;
use RadishConcepts\TwoFactor\Storage\UserMeta;

final class UserMetaMethodTest extends TestCase {

	protected function setUp(): void {
		global $rt2fa_test_user_meta;
		$rt2fa_test_user_meta = [];
	}

	public function test_no_meta_means_not_enrolled_and_no_method(): void {
		self::assertNull( UserMeta::instance()->get_method( 1 ) );
		self::assertFalse( UserMeta::instance()->is_enrolled( 1 ) );
	}

	public function test_set_secret_marks_user_as_totp_enrolled(): void {
		UserMeta::instance()->set_secret( 1, 'JBSWY3DPEHPK3PXP' );

		self::assertSame( Method::TOTP, UserMeta::instance()->get_method( 1 ) );
		self::assertTrue( UserMeta::instance()->is_enrolled( 1 ) );
	}

	public function test_enroll_email_marks_user_as_email_and_clears_secret(): void {
		UserMeta::instance()->set_secret( 1, 'JBSWY3DPEHPK3PXP' );
		UserMeta::instance()->enroll_email( 1 );

		self::assertSame( Method::EMAIL, UserMeta::instance()->get_method( 1 ) );
		self::assertTrue( UserMeta::instance()->is_enrolled( 1 ) );
		self::assertNull( UserMeta::instance()->get_secret( 1 ) );
	}

	public function test_back_compat_secret_without_method_treated_as_totp(): void {
		// Simulate a pre-method-release user: only a TOTP secret stored, no META_METHOD.
		global $rt2fa_test_user_meta;
		\update_user_meta( 1, UserMeta::META_SECRET, \RadishConcepts\TwoFactor\Security\Crypto::encrypt( 'JBSWY3DPEHPK3PXP' ) );

		self::assertSame( Method::TOTP, UserMeta::instance()->get_method( 1 ) );
		self::assertTrue( UserMeta::instance()->is_enrolled( 1 ) );
	}

	public function test_set_method_rejects_invalid_input(): void {
		UserMeta::instance()->set_method( 1, 'not-a-method' );

		self::assertNull( UserMeta::instance()->get_method( 1 ) );
	}

	public function test_clear_wipes_method_and_secret(): void {
		UserMeta::instance()->set_secret( 1, 'JBSWY3DPEHPK3PXP' );
		UserMeta::instance()->clear( 1 );

		self::assertFalse( UserMeta::instance()->is_enrolled( 1 ) );
		self::assertNull( UserMeta::instance()->get_method( 1 ) );
	}
}
