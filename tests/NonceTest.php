<?php

declare( strict_types=1 );

namespace RadishConcepts\TwoFactor\Tests;

use PHPUnit\Framework\TestCase;
use RadishConcepts\TwoFactor\Auth\Nonce;

final class NonceTest extends TestCase {

	protected function setUp(): void {
		global $rt2fa_test_transients;
		$rt2fa_test_transients = [];
	}

	public function test_create_returns_32_hex_token(): void {
		$token = Nonce::instance()->create( 7, Nonce::MODE_SETUP, '/wp-admin/' );

		self::assertSame( 32, strlen( $token ) );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $token );
	}

	public function test_peek_returns_payload(): void {
		$token   = Nonce::instance()->create( 42, Nonce::MODE_CHALLENGE, '/dashboard' );
		$payload = Nonce::instance()->peek( $token );

		self::assertSame( 42, $payload['user_id'] );
		self::assertSame( Nonce::MODE_CHALLENGE, $payload['mode'] );
		self::assertSame( '/dashboard', $payload['redirect_to'] );
	}

	public function test_peek_carries_extra_payload(): void {
		$token   = Nonce::instance()->create( 1, Nonce::MODE_SETUP, '/', [
			'pending_secret' => 'JBSWY3DPEHPK3PXP',
			'remember'       => true,
		] );
		$payload = Nonce::instance()->peek( $token );

		self::assertSame( 'JBSWY3DPEHPK3PXP', $payload['pending_secret'] );
		self::assertTrue( $payload['remember'] );
	}

	public function test_peek_does_not_consume(): void {
		$token = Nonce::instance()->create( 1, Nonce::MODE_SETUP, '/' );

		Nonce::instance()->peek( $token );
		self::assertNotNull( Nonce::instance()->peek( $token ), 'peek must be idempotent' );
	}

	public function test_consume_deletes_the_nonce(): void {
		$token = Nonce::instance()->create( 1, Nonce::MODE_SETUP, '/' );

		Nonce::instance()->consume( $token );
		self::assertNull( Nonce::instance()->peek( $token ) );
	}

	public function test_peek_returns_null_for_invalid_token(): void {
		self::assertNull( Nonce::instance()->peek( '' ) );
		self::assertNull( Nonce::instance()->peek( 'never-issued' ) );
	}

	public function test_update_replaces_payload_in_place(): void {
		$token = Nonce::instance()->create( 5, Nonce::MODE_SETUP, '/' );

		Nonce::instance()->update( $token, [
			'user_id'     => 5,
			'mode'        => Nonce::MODE_BACKUP,
			'redirect_to' => '/',
			'plain_codes' => [ 'AAAA-BBBB' ],
		] );

		$payload = Nonce::instance()->peek( $token );
		self::assertSame( Nonce::MODE_BACKUP, $payload['mode'] );
		self::assertSame( [ 'AAAA-BBBB' ], $payload['plain_codes'] );
	}

	public function test_lookup_key_is_hashed(): void {
		global $rt2fa_test_transients;

		$token = Nonce::instance()->create( 1, Nonce::MODE_SETUP, '/' );

		// The transient key must NOT contain the raw token.
		foreach ( array_keys( $rt2fa_test_transients ) as $stored_key ) {
			self::assertStringNotContainsString( $token, $stored_key );
		}
	}
}
