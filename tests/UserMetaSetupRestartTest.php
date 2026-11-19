<?php

declare( strict_types=1 );

namespace RadishConcepts\TwoFactor\Tests;

use PHPUnit\Framework\TestCase;
use RadishConcepts\TwoFactor\Storage\UserMeta;

final class UserMetaSetupRestartTest extends TestCase {

	protected function setUp(): void {
		global $rt2fa_test_user_meta;
		$rt2fa_test_user_meta = [];
	}

	public function test_no_restart_recorded_means_no_notice(): void {
		self::assertFalse( UserMeta::instance()->take_setup_restarted( 1 ) );
	}

	public function test_recorded_restart_is_reported_once(): void {
		UserMeta::instance()->mark_setup_restarted( 1 );

		self::assertTrue( UserMeta::instance()->take_setup_restarted( 1 ) );
		self::assertFalse( UserMeta::instance()->take_setup_restarted( 1 ) );
	}

	public function test_restart_belongs_to_its_own_user(): void {
		UserMeta::instance()->mark_setup_restarted( 1 );

		self::assertFalse( UserMeta::instance()->take_setup_restarted( 2 ) );
		self::assertTrue( UserMeta::instance()->take_setup_restarted( 1 ) );
	}

	public function test_old_restart_is_dropped_without_notice(): void {
		\update_user_meta( 1, UserMeta::META_SETUP_RESTARTED_AT, time() - UserMeta::SETUP_RESTART_NOTICE_TTL - 1 );

		self::assertFalse( UserMeta::instance()->take_setup_restarted( 1 ) );
		self::assertSame( '', \get_user_meta( 1, UserMeta::META_SETUP_RESTARTED_AT, true ) );
	}

	public function test_clear_drops_a_pending_restart(): void {
		UserMeta::instance()->mark_setup_restarted( 1 );
		UserMeta::instance()->clear( 1 );

		self::assertFalse( UserMeta::instance()->take_setup_restarted( 1 ) );
	}
}
