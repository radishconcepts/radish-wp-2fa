<?php

declare( strict_types=1 );

namespace RadishConcepts\TwoFactor\Tests;

use PHPUnit\Framework\TestCase;
use RadishConcepts\TwoFactor\Auth\Nonce;
use RadishConcepts\TwoFactor\Security\Crypto;

final class NonceTest extends TestCase {

	protected function setUp(): void {
		global $rt2fa_test_transients, $rt2fa_test_transient_ttls, $rt2fa_test_user_meta;
		$rt2fa_test_transients     = [];
		$rt2fa_test_transient_ttls = [];
		$rt2fa_test_user_meta      = [];
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
		$token = Nonce::instance()->create( 1, Nonce::MODE_SETUP, '/' );

		// The meta key must NOT contain the raw token.
		foreach ( array_keys( $this->stored_meta( 1 ) ) as $stored_key ) {
			self::assertStringNotContainsString( $token, $stored_key );
		}
	}

	// ──────────────────────────────────────────────────────────────────────
	//  Storage: user meta, so an object cache flush can't drop a flow
	// ──────────────────────────────────────────────────────────────────────

	public function test_nonce_survives_an_object_cache_flush(): void {
		global $rt2fa_test_transients;

		$token = Nonce::instance()->create( 3, Nonce::MODE_SETUP, '/', [ 'pending_secret' => 'JBSWY3DPEHPK3PXP' ] );

		$rt2fa_test_transients = []; // What `wp cache flush` does to transients on Redis.

		self::assertSame( 'JBSWY3DPEHPK3PXP', Nonce::instance()->peek( $token )['pending_secret'] ?? null );
	}

	public function test_receipt_survives_an_object_cache_flush(): void {
		global $rt2fa_test_transients;

		$token = Nonce::instance()->create( 3, Nonce::MODE_CHALLENGE, '/' );
		Nonce::instance()->complete( $token, 3, '/wp-admin/' );

		$rt2fa_test_transients = [];

		self::assertSame( '/wp-admin/', Nonce::instance()->peek_completed( $token )['redirect_to'] ?? null );
	}

	public function test_payload_is_encrypted_at_rest(): void {
		Nonce::instance()->create( 3, Nonce::MODE_SETUP, '/', [
			'pending_secret' => 'JBSWY3DPEHPK3PXP',
			'plain_codes'    => [ 'AAAA-BBBB' ],
		] );

		foreach ( $this->stored_meta( 3 ) as $value ) {
			self::assertStringNotContainsString( 'JBSWY3DPEHPK3PXP', (string) $value );
			self::assertStringNotContainsString( 'AAAA-BBBB', (string) $value );
		}
	}

	public function test_an_expired_nonce_is_gone_and_its_row_deleted(): void {
		$token = Nonce::instance()->create( 3, Nonce::MODE_CHALLENGE, '/' );
		$this->expire( 3 );

		self::assertNull( Nonce::instance()->peek( $token ) );
		self::assertSame( [], $this->stored_meta( 3 ) );
	}

	public function test_an_expired_receipt_is_gone(): void {
		$token = Nonce::instance()->create( 3, Nonce::MODE_CHALLENGE, '/' );
		Nonce::instance()->complete( $token, 3, '/' );
		$this->expire( 3 );

		self::assertNull( Nonce::instance()->peek_completed( $token ) );
	}

	public function test_a_corrupt_row_reads_as_no_nonce(): void {
		global $rt2fa_test_user_meta;

		$token = Nonce::instance()->create( 3, Nonce::MODE_CHALLENGE, '/' );
		$key   = array_key_first( $this->stored_meta( 3 ) );

		$rt2fa_test_user_meta[3][ $key ] = 'not-a-sealed-payload';

		self::assertNull( Nonce::instance()->peek( $token ) );
	}

	public function test_create_purges_the_users_expired_rows_only(): void {
		$old   = Nonce::instance()->create( 3, Nonce::MODE_CHALLENGE, '/' );
		$other = Nonce::instance()->create( 4, Nonce::MODE_CHALLENGE, '/' );
		$this->expire( 3 );
		$this->expire( 4 );

		$fresh = Nonce::instance()->create( 3, Nonce::MODE_CHALLENGE, '/' );

		self::assertCount( 1, $this->stored_meta( 3 ), 'the expired row of user 3 is purged' );
		self::assertNotNull( Nonce::instance()->peek( $fresh ) );
		self::assertCount( 1, $this->stored_meta( 4 ), 'another user is left alone' );
		self::assertNull( Nonce::instance()->peek( $old ) );
		self::assertNull( Nonce::instance()->peek( $other ) );
	}

	public function test_create_keeps_the_users_live_tokens(): void {
		$first  = Nonce::instance()->create( 3, Nonce::MODE_SETUP, '/' );
		$second = Nonce::instance()->create( 3, Nonce::MODE_SETUP, '/' );

		self::assertNotNull( Nonce::instance()->peek( $first ), 'a second tab must not kill the first' );
		self::assertNotNull( Nonce::instance()->peek( $second ) );
	}

	public function test_consume_deletes_the_row(): void {
		$token = Nonce::instance()->create( 3, Nonce::MODE_CHALLENGE, '/' );

		Nonce::instance()->consume( $token );

		self::assertSame( [], $this->stored_meta( 3 ) );
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
		global $rt2fa_test_user_meta;

		Nonce::instance()->touch( 'never-issued' );
		Nonce::instance()->touch( '' );

		self::assertSame( [], $rt2fa_test_user_meta );
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
		$token = Nonce::instance()->create( 1, Nonce::MODE_CHALLENGE, '/' );
		Nonce::instance()->complete( $token, 1, '/' );

		foreach ( array_keys( $this->stored_meta( 1 ) ) as $stored_key ) {
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
	 * waiting.
	 */
	private function rewind_created_at( string $token, int $seconds ): void {
		$payload               = Nonce::instance()->peek( $token );
		$payload['created_at'] = $payload['created_at'] - $seconds;

		Nonce::instance()->update( $token, $payload );
	}

	private function stored_ttl( string $token ): int {
		$sealed = $this->stored_meta( 1 )[ '_radish_2fa_flow_' . hash( 'sha256', $token ) ];

		return $this->unseal( $sealed )['expires_at'] - time();
	}

	/**
	 * The user's flow and receipt rows, keyed by meta key.
	 *
	 * @return array<string,mixed>
	 */
	private function stored_meta( int $user_id ): array {
		global $rt2fa_test_user_meta;

		return array_filter(
			$rt2fa_test_user_meta[ $user_id ] ?? [],
			static fn( $key ) => str_starts_with( (string) $key, '_radish_2fa_flow_' ) || str_starts_with( (string) $key, '_radish_2fa_receipt_' ),
			ARRAY_FILTER_USE_KEY
		);
	}

	/**
	 * Move every stored row of the user one second past its expiry.
	 */
	private function expire( int $user_id ): void {
		global $rt2fa_test_user_meta;

		foreach ( $this->stored_meta( $user_id ) as $key => $sealed ) {
			$record               = $this->unseal( $sealed );
			$record['expires_at'] = time() - 1;

			$rt2fa_test_user_meta[ $user_id ][ $key ] = Crypto::encrypt( (string) json_encode( $record ) );
		}
	}

	/**
	 * @return array{expires_at:int,data:array<string,mixed>}
	 */
	private function unseal( string $sealed ): array {
		return json_decode( (string) Crypto::decrypt( $sealed ), true );
	}
}
