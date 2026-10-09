<?php

declare( strict_types=1 );

namespace RadishConcepts\TwoFactor\Tests;

use PHPUnit\Framework\TestCase;
use RadishConcepts\TwoFactor\Security\AttemptLimit;

final class AttemptLimitTest extends TestCase {

	protected function setUp(): void {
		global $rt2fa_test_transients, $rt2fa_test_actions;
		$rt2fa_test_transients = [];
		$rt2fa_test_actions    = [];

		global $wpdb;
		$wpdb->queries     = [];
		$wpdb->lock_result = '1';
	}

	public function test_fresh_user_is_not_locked(): void {
		self::assertFalse( AttemptLimit::instance()->is_locked( 1 ) );
	}

	public function test_record_failure_returns_count_in_window(): void {
		self::assertSame( 1, AttemptLimit::instance()->record_failure( 1 ) );
		self::assertSame( 2, AttemptLimit::instance()->record_failure( 1 ) );
	}

	public function test_user_is_locked_after_max_failures(): void {
		for ( $i = 0; $i < AttemptLimit::MAX_PER_USER - 1; $i++ ) {
			AttemptLimit::instance()->record_failure( 3 );
		}
		self::assertFalse( AttemptLimit::instance()->is_locked( 3 ), 'One below the limit is still allowed' );

		AttemptLimit::instance()->record_failure( 3 );

		self::assertTrue( AttemptLimit::instance()->is_locked( 3 ) );
	}

	public function test_failures_are_counted_per_user(): void {
		for ( $i = 0; $i < AttemptLimit::MAX_PER_USER; $i++ ) {
			AttemptLimit::instance()->record_failure( 4 );
		}

		self::assertTrue( AttemptLimit::instance()->is_locked( 4 ) );
		self::assertFalse( AttemptLimit::instance()->is_locked( 5 ) );
	}

	public function test_failures_outside_the_window_are_forgotten(): void {
		global $rt2fa_test_transients;

		$old = array_fill( 0, AttemptLimit::MAX_PER_USER, time() - AttemptLimit::WINDOW_SECONDS - 1 );
		$rt2fa_test_transients['r2fa_fail_6'] = $old;

		self::assertFalse( AttemptLimit::instance()->is_locked( 6 ) );
		self::assertSame( 1, AttemptLimit::instance()->record_failure( 6 ) );
	}

	public function test_window_transient_is_stored_with_window_ttl(): void {
		global $rt2fa_test_transient_ttls;

		AttemptLimit::instance()->record_failure( 7 );

		self::assertSame( AttemptLimit::WINDOW_SECONDS, $rt2fa_test_transient_ttls['r2fa_fail_7'] );
	}

	public function test_clear_resets_the_user(): void {
		for ( $i = 0; $i < AttemptLimit::MAX_PER_USER; $i++ ) {
			AttemptLimit::instance()->record_failure( 8 );
		}

		AttemptLimit::instance()->clear( 8 );

		self::assertFalse( AttemptLimit::instance()->is_locked( 8 ) );
	}

	public function test_record_failure_fires_action_for_logging(): void {
		$seen = [];
		add_action(
			'radish_2fa_verification_failed',
			static function ( int $user_id, int $count ) use ( &$seen ): void {
				$seen[] = [ $user_id, $count ];
			},
			10,
			2
		);

		AttemptLimit::instance()->record_failure( 9 );

		self::assertSame( [ [ 9, 1 ] ], $seen );
	}

	public function test_is_exhausted_stops_at_token_limit(): void {
		self::assertFalse( AttemptLimit::is_exhausted( AttemptLimit::MAX_PER_TOKEN - 1, 1 ) );
		self::assertTrue( AttemptLimit::is_exhausted( AttemptLimit::MAX_PER_TOKEN, 1 ) );
	}

	public function test_is_exhausted_stops_at_user_limit(): void {
		self::assertFalse( AttemptLimit::is_exhausted( 1, AttemptLimit::MAX_PER_USER - 1 ) );
		self::assertTrue( AttemptLimit::is_exhausted( 1, AttemptLimit::MAX_PER_USER ) );
	}

	public function test_acquire_takes_a_per_user_database_lock(): void {
		global $wpdb;

		self::assertTrue( AttemptLimit::instance()->acquire( 12 ) );
		AttemptLimit::instance()->release( 12 );

		self::assertStringStartsWith( 'SELECT GET_LOCK(', $wpdb->queries[0] );
		self::assertStringContainsString( '_12', $wpdb->queries[0] );
		self::assertStringStartsWith( 'SELECT RELEASE_LOCK(', $wpdb->queries[1] );
	}

	public function test_lock_names_differ_per_user_and_fit_mysql_limit(): void {
		global $wpdb;

		AttemptLimit::instance()->acquire( 1 );
		AttemptLimit::instance()->acquire( 2 );

		self::assertNotSame( $wpdb->queries[0], $wpdb->queries[1] );
		preg_match( "/GET_LOCK\\('([^']+)'/", $wpdb->queries[0], $m );
		self::assertLessThanOrEqual( 64, strlen( $m[1] ) );
	}

	public function test_acquire_fails_when_lock_times_out(): void {
		global $wpdb;
		$wpdb->lock_result = '0';

		self::assertFalse( AttemptLimit::instance()->acquire( 13 ) );
	}

	public function test_acquire_proceeds_when_database_has_no_locks(): void {
		global $wpdb;
		$wpdb->lock_result = null;

		self::assertTrue( AttemptLimit::instance()->acquire( 14 ) );
	}

	public function test_token_failures_are_counted_per_token(): void {
		self::assertSame( 0, AttemptLimit::instance()->token_failures( 'tok-a' ) );
		self::assertSame( 1, AttemptLimit::instance()->record_token_failure( 'tok-a' ) );
		self::assertSame( 2, AttemptLimit::instance()->record_token_failure( 'tok-a' ) );
		self::assertSame( 0, AttemptLimit::instance()->token_failures( 'tok-b' ) );
	}

	public function test_token_counter_key_does_not_contain_the_token(): void {
		global $rt2fa_test_transients;

		AttemptLimit::instance()->record_token_failure( 'secret-token-value' );

		foreach ( array_keys( $rt2fa_test_transients ) as $key ) {
			self::assertStringNotContainsString( 'secret-token-value', $key );
		}
	}

	public function test_token_counter_outlives_the_nonce_idle_window(): void {
		global $rt2fa_test_transient_ttls;

		AttemptLimit::instance()->record_token_failure( 'tok-c' );

		self::assertSame( 30 * MINUTE_IN_SECONDS, $rt2fa_test_transient_ttls[ 'r2fa_tfail_' . hash( 'sha256', 'tok-c' ) ] );
	}
}
