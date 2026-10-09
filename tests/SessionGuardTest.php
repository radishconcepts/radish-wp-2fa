<?php

declare( strict_types=1 );

namespace RadishConcepts\TwoFactor\Tests;

use PHPUnit\Framework\TestCase;
use RadishConcepts\TwoFactor\Auth\SessionGuard;

final class SessionGuardTest extends TestCase {

	public function test_mark_adds_verification_time_without_touching_input(): void {
		$session = [ 'expiration' => time() + 100, 'login' => time() ];

		$marked = SessionGuard::mark( $session );

		self::assertArrayNotHasKey( SessionGuard::SESSION_KEY, $session, 'Input must not be mutated' );
		self::assertEqualsWithDelta( time(), $marked[ SessionGuard::SESSION_KEY ], 2 );
		self::assertSame( $session['login'], $marked['login'] );
	}

	public function test_marked_session_is_verified(): void {
		self::assertTrue( SessionGuard::is_verified( SessionGuard::mark( [ 'login' => time() ] ) ) );
	}

	public function test_unmarked_session_is_not_verified(): void {
		self::assertFalse( SessionGuard::is_verified( [ 'login' => time(), 'ua' => 'x' ] ) );
	}

	public function test_missing_session_is_not_verified(): void {
		self::assertFalse( SessionGuard::is_verified( null ) );
	}

	public function test_bogus_marker_is_not_verified(): void {
		self::assertFalse( SessionGuard::is_verified( [ SessionGuard::SESSION_KEY => 'yes' ] ) );
		self::assertFalse( SessionGuard::is_verified( [ SessionGuard::SESSION_KEY => 0 ] ) );
	}

	public function test_long_session_counts_as_remembered(): void {
		$login = time();

		self::assertTrue( SessionGuard::was_remembered( [ 'login' => $login, 'expiration' => $login + 14 * DAY_IN_SECONDS ] ) );
		self::assertFalse( SessionGuard::was_remembered( [ 'login' => $login, 'expiration' => $login + 2 * DAY_IN_SECONDS ] ) );
		self::assertFalse( SessionGuard::was_remembered( [] ) );
	}
}
