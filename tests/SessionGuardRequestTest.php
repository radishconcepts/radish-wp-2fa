<?php

declare( strict_types=1 );

namespace RadishConcepts\TwoFactor\Tests;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use RadishConcepts\TwoFactor\Auth\SessionGuard;
use WP_User;

/**
 * Request-level behaviour of SessionGuard. Each test runs in its own process
 * because the guard remembers per request which cookies were validated.
 */
#[RunTestsInSeparateProcesses]
final class SessionGuardRequestTest extends TestCase {

	private WP_User $user;

	protected function setUp(): void {
		global $rt2fa_test_sessions, $rt2fa_test_session_token;
		$rt2fa_test_sessions      = [];
		$rt2fa_test_session_token = '';
		$this->user               = new WP_User( 5, [ 'editor' ], 'eve' );
	}

	public function test_request_without_session_is_not_verified(): void {
		self::assertFalse( SessionGuard::instance()->request_is_verified( $this->user ) );
	}

	public function test_marked_logged_in_session_is_verified(): void {
		$this->add_session( 'li', true );
		$this->use_logged_in_cookie( 'li' );

		self::assertTrue( SessionGuard::instance()->request_is_verified( $this->user ) );
	}

	public function test_unmarked_auth_cookie_fails_even_with_marked_logged_in_cookie(): void {
		$this->add_session( 'li', true );
		$this->add_session( 'auth', false );
		$this->use_logged_in_cookie( 'li' );

		SessionGuard::instance()->capture_validated_token( [ 'token' => 'li' ], $this->user );
		SessionGuard::instance()->capture_validated_token( [ 'token' => 'auth' ], $this->user );

		self::assertFalse( SessionGuard::instance()->request_is_verified( $this->user ) );
	}

	public function test_all_validated_cookies_marked_is_verified(): void {
		$this->add_session( 'li', true );
		$this->add_session( 'auth', true );

		SessionGuard::instance()->capture_validated_token( [ 'token' => 'li' ], $this->user );
		SessionGuard::instance()->capture_validated_token( [ 'token' => 'auth' ], $this->user );

		self::assertTrue( SessionGuard::instance()->request_is_verified( $this->user ) );
	}

	public function test_tokens_of_other_users_are_ignored(): void {
		$this->add_session( 'li', true );
		$this->use_logged_in_cookie( 'li' );

		SessionGuard::instance()->capture_validated_token( [ 'token' => 'other' ], new WP_User( 6, [], 'sam' ) );

		self::assertTrue( SessionGuard::instance()->request_is_verified( $this->user ) );
	}

	public function test_new_session_inherits_mark_from_verified_request(): void {
		$this->add_session( 'li', true );
		$this->use_logged_in_cookie( 'li' );

		$session = SessionGuard::instance()->carry_mark_over( [ 'login' => time() ], 5 );

		self::assertTrue( SessionGuard::is_verified( $session ) );
	}

	public function test_new_session_for_other_user_does_not_inherit_mark(): void {
		$this->add_session( 'li', true );
		$this->use_logged_in_cookie( 'li' );

		$session = SessionGuard::instance()->carry_mark_over( [ 'login' => time() ], 6 );

		self::assertFalse( SessionGuard::is_verified( $session ) );
	}

	public function test_new_session_from_unverified_request_stays_unmarked(): void {
		$this->add_session( 'li', false );
		$this->use_logged_in_cookie( 'li' );

		$session = SessionGuard::instance()->carry_mark_over( [ 'login' => time() ], 5 );

		self::assertFalse( SessionGuard::is_verified( $session ) );
	}

	public function test_mark_survives_old_session_being_destroyed_in_same_request(): void {
		$this->add_session( 'li', true );
		$this->use_logged_in_cookie( 'li' );

		// Enforcement checks the request early; a password change later destroys the old session.
		self::assertTrue( SessionGuard::instance()->request_is_verified( $this->user ) );
		\WP_Session_Tokens::get_instance( 5 )->destroy( 'li' );

		$session = SessionGuard::instance()->carry_mark_over( [ 'login' => time() ], 5 );

		self::assertTrue( SessionGuard::is_verified( $session ) );
	}

	private function add_session( string $token, bool $marked ): void {
		global $rt2fa_test_sessions;

		$session = [ 'login' => time(), 'expiration' => time() + 3600 ];
		$rt2fa_test_sessions[5][ $token ] = $marked ? SessionGuard::mark( $session ) : $session;
	}

	private function use_logged_in_cookie( string $token ): void {
		global $rt2fa_test_session_token;
		$rt2fa_test_session_token = $token;
	}
}
