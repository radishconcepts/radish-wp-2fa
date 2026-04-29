<?php

declare( strict_types=1 );

namespace RadishConcepts\TwoFactor\Tests;

use PHPUnit\Framework\TestCase;
use RadishConcepts\TwoFactor\Security\EmailRateLimit;

final class EmailRateLimitTest extends TestCase {

	protected function setUp(): void {
		global $rt2fa_test_transients;
		$rt2fa_test_transients = [];
	}

	public function test_first_send_is_allowed(): void {
		self::assertTrue( EmailRateLimit::instance()->can_send( 1 ) );
	}

	public function test_record_send_starts_cooldown(): void {
		EmailRateLimit::instance()->record_send( 1 );

		self::assertFalse( EmailRateLimit::instance()->can_send( 1 ) );
		self::assertGreaterThan( 0, EmailRateLimit::instance()->seconds_until_next_send( 1 ) );
	}

	public function test_cooldown_is_per_user(): void {
		EmailRateLimit::instance()->record_send( 1 );

		self::assertFalse( EmailRateLimit::instance()->can_send( 1 ) );
		self::assertTrue( EmailRateLimit::instance()->can_send( 2 ) );
	}

	public function test_hourly_cap_blocks_after_threshold(): void {
		global $rt2fa_test_transients;

		// Pre-fill with 5 sends inside the rolling window, far enough back that
		// the per-send cooldown has expired.
		$timestamps = [];
		for ( $i = 0; $i < EmailRateLimit::HOURLY_MAX; $i++ ) {
			$timestamps[] = time() - 60;
		}
		$rt2fa_test_transients[ 'r2fa_email_wd_7' ]  = $timestamps;
		$rt2fa_test_transients[ 'r2fa_email_cd_7' ]  = time() - 1; // expired cooldown

		self::assertFalse( EmailRateLimit::instance()->can_send( 7 ) );
	}

	public function test_clear_resets_state(): void {
		EmailRateLimit::instance()->record_send( 9 );
		EmailRateLimit::instance()->clear( 9 );

		self::assertSame( 0, EmailRateLimit::instance()->seconds_until_next_send( 9 ) );
		self::assertTrue( EmailRateLimit::instance()->can_send( 9 ) );
	}

	public function test_old_window_entries_are_pruned(): void {
		global $rt2fa_test_transients;

		// 5 entries, but all far outside the 1-hour window — should be pruned.
		$rt2fa_test_transients[ 'r2fa_email_wd_3' ] = [
			time() - 7200,
			time() - 7100,
			time() - 7000,
			time() - 6900,
			time() - 6800,
		];

		self::assertTrue( EmailRateLimit::instance()->can_send( 3 ) );
	}
}
