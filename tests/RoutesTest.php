<?php

declare( strict_types=1 );

namespace RadishConcepts\TwoFactor\Tests;

use PHPUnit\Framework\TestCase;
use RadishConcepts\TwoFactor\Auth\Nonce;
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

	public function test_challenge_token_only_opens_the_challenge_route(): void {
		self::assertSame( Routes::ACTION_CHALLENGE, Routes::action_for_mode( Nonce::MODE_CHALLENGE ) );
	}

	public function test_setup_and_backup_tokens_open_the_setup_route(): void {
		self::assertSame( Routes::ACTION_SETUP, Routes::action_for_mode( Nonce::MODE_SETUP ) );
		self::assertSame( Routes::ACTION_SETUP, Routes::action_for_mode( Nonce::MODE_BACKUP ) );
	}

	public function test_unknown_mode_opens_no_route(): void {
		self::assertSame( '', Routes::action_for_mode( '' ) );
		self::assertSame( '', Routes::action_for_mode( 'admin' ) );
	}

	public function test_url_for_action_points_at_the_matching_route(): void {
		self::assertStringContainsString( '/2fa/challenge/', Routes::url_for_action( Routes::ACTION_CHALLENGE, 'abc' ) );
		self::assertStringContainsString( '/2fa/setup/', Routes::url_for_action( Routes::ACTION_SETUP, 'abc' ) );
	}
}
