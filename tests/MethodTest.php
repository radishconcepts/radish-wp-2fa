<?php

declare( strict_types=1 );

namespace RadishConcepts\TwoFactor\Tests;

use PHPUnit\Framework\TestCase;
use RadishConcepts\TwoFactor\Methods\Method;

final class MethodTest extends TestCase {

	public function test_all_returns_known_methods(): void {
		self::assertSame( [ 'totp', 'email' ], Method::all() );
	}

	public function test_is_valid_accepts_known_and_rejects_unknown(): void {
		self::assertTrue( Method::is_valid( Method::TOTP ) );
		self::assertTrue( Method::is_valid( Method::EMAIL ) );
		self::assertFalse( Method::is_valid( 'sms' ) );
		self::assertFalse( Method::is_valid( '' ) );
	}

	public function test_label_returns_translatable_string_for_known_methods(): void {
		self::assertNotSame( '', Method::label( Method::TOTP ) );
		self::assertNotSame( '', Method::label( Method::EMAIL ) );
		self::assertSame( 'unknown', Method::label( 'unknown' ) );
	}
}
