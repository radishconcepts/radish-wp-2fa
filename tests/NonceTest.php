<?php

declare( strict_types=1 );

namespace RadishConcepts\TwoFactor\Tests;

use PHPUnit\Framework\TestCase;
use RadishConcepts\TwoFactor\Auth\Nonce;

final class NonceTest extends TestCase {

	protected function setUp(): void {
		global $rt2fa_test_transients, $rt2fa_test_transient_ttls;
		$rt2fa_test_transients     = [];
		$rt2fa_test_transient_ttls = [];
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

	public function test_update_preserves_created_at(): void {
		$token   = Nonce::instance()->create( 5, Nonce::MODE_SETUP, '/' );
		$created = Nonce::instance()->peek( $token )['created_at'];

		Nonce::instance()->update( $token, [
			'user_id'     => 5,
			'mode'        => Nonce::MODE_BACKUP,
			'redirect_to' => '/',
		] );

		self::assertSame( $created, Nonce::instance()->peek( $token )['created_at'] );
	}

	public function test_lookup_key_is_hashed(): void {
		global $rt2fa_test_transients;

		$token = Nonce::instance()->create( 1, Nonce::MODE_SETUP, '/' );

		// The transient key must NOT contain the raw token.
		foreach ( array_keys( $rt2fa_test_transients ) as $stored_key ) {
			self::assertStringNotContainsString( $token, $stored_key );
		}
	}

	// ──────────────────────────────────────────────────────────────────────
	//  Idle window (touch) + absolute ceiling
	// ──────────────────────────────────────────────────────────────────────

	public function test_touch_restarts_the_idle_window(): void {
		$token = Nonce::instance()->create( 1, Nonce::MODE_CHALLENGE, '/' );
		$this->rewind_created_at( $token, 4 * MINUTE_IN_SECONDS );

		Nonce::instance()->touch( $token );

		self::assertNotNull( Nonce::instance()->peek( $token ) );
		self::assertSame( 5 * MINUTE_IN_SECONDS, $this->stored_ttl( $token ) );
	}

	public function test_touch_clamps_ttl_to_the_absolute_ceiling(): void {
		$token = Nonce::instance()->create( 1, Nonce::MODE_CHALLENGE, '/' );
		// 28 minutes in: only 2 of the 30-minute ceiling remain.
		$this->rewind_created_at( $token, 28 * MINUTE_IN_SECONDS );

		Nonce::instance()->touch( $token );

		self::assertSame( 2 * MINUTE_IN_SECONDS, $this->stored_ttl( $token ) );
	}

	public function test_touch_drops_a_nonce_past_the_absolute_ceiling(): void {
		$token = Nonce::instance()->create( 1, Nonce::MODE_CHALLENGE, '/' );
		$this->rewind_created_at( $token, 31 * MINUTE_IN_SECONDS );

		Nonce::instance()->touch( $token );

		self::assertNull( Nonce::instance()->peek( $token ) );
	}

	public function test_touch_is_a_noop_for_unknown_tokens(): void {
		global $rt2fa_test_transients;

		Nonce::instance()->touch( 'never-issued' );
		Nonce::instance()->touch( '' );

		self::assertSame( [], $rt2fa_test_transients );
	}

	// ──────────────────────────────────────────────────────────────────────
	//  Completion receipt
	// ──────────────────────────────────────────────────────────────────────

	public function test_complete_consumes_the_nonce(): void {
		$token = Nonce::instance()->create( 9, Nonce::MODE_CHALLENGE, '/wp-admin/' );

		Nonce::instance()->complete( $token, 9, '/wp-admin/' );

		self::assertNull( Nonce::instance()->peek( $token ) );
	}

	public function test_completed_token_remains_recognisable(): void {
		$token = Nonce::instance()->create( 9, Nonce::MODE_CHALLENGE, '/wp-admin/' );

		Nonce::instance()->complete( $token, 9, '/wp-admin/profile.php' );

		$receipt = Nonce::instance()->peek_completed( $token );
		self::assertNotNull( $receipt, 'a repeated request must be able to tell "done" from "expired"' );
		self::assertSame( 9, $receipt['user_id'] );
		self::assertSame( '/wp-admin/profile.php', $receipt['redirect_to'] );
	}

	public function test_peek_completed_returns_null_without_a_receipt(): void {
		$token = Nonce::instance()->create( 9, Nonce::MODE_CHALLENGE, '/' );

		self::assertNull( Nonce::instance()->peek_completed( $token ) );
		self::assertNull( Nonce::instance()->peek_completed( 'never-issued' ) );
		self::assertNull( Nonce::instance()->peek_completed( '' ) );
	}

	public function test_receipt_key_does_not_contain_the_raw_token(): void {
		global $rt2fa_test_transients;

		$token = Nonce::instance()->create( 1, Nonce::MODE_CHALLENGE, '/' );
		Nonce::instance()->complete( $token, 1, '/' );

		foreach ( array_keys( $rt2fa_test_transients ) as $stored_key ) {
			self::assertStringNotContainsString( $token, $stored_key );
		}
	}

	public function test_receipt_carries_no_secrets(): void {
		$token = Nonce::instance()->create( 1, Nonce::MODE_SETUP, '/', [
			'pending_secret'  => 'JBSWY3DPEHPK3PXP',
			'email_code_hash' => 'deadbeef',
			'plain_codes'     => [ 'AAAA-BBBB' ],
		] );

		Nonce::instance()->complete( $token, 1, '/' );

		self::assertSame(
			[ 'user_id', 'redirect_to' ],
			array_keys( Nonce::instance()->peek_completed( $token ) )
		);
	}

	/**
	 * Backdate a stored nonce so age-dependent behaviour can be exercised without
	 * waiting (the transient stub ignores TTLs, so age lives in the payload).
	 */
	private function rewind_created_at( string $token, int $seconds ): void {
		$payload               = Nonce::instance()->peek( $token );
		$payload['created_at'] = $payload['created_at'] - $seconds;

		Nonce::instance()->update( $token, $payload );
	}

	private function stored_ttl( string $token ): int {
		global $rt2fa_test_transient_ttls;

		return (int) $rt2fa_test_transient_ttls[ 'r2fa_n_' . hash( 'sha256', $token ) ];
	}
}
