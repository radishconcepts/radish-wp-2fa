<?php

declare( strict_types=1 );

namespace RadishConcepts\TwoFactor\Tests;

use PHPUnit\Framework\TestCase;
use RadishConcepts\TwoFactor\Routes;

final class RoutesTest extends TestCase {

	public function test_setup_url_uses_token_param(): void {
		$url = Routes::setup_url( 'abc123' );

		self::assertStringContainsString( '/2fa/setup/', $url );
		self::assertStringContainsString( 't=abc123', $url );
	}

	public function test_challenge_url_uses_token_param(): void {
		$url = Routes::challenge_url( 'def456' );

		self::assertStringContainsString( '/2fa/challenge/', $url );
		self::assertStringContainsString( 't=def456', $url );
	}

	public function test_constants_are_unique(): void {
		self::assertNotSame( Routes::ACTION_SETUP, Routes::ACTION_CHALLENGE );
	}
}
