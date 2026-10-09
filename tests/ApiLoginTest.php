<?php

declare( strict_types=1 );

namespace RadishConcepts\TwoFactor\Tests;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use RadishConcepts\TwoFactor\Admin\Settings;
use RadishConcepts\TwoFactor\Auth\ApiLogin;
use WP_Error;
use WP_User;

/**
 * Each test runs in its own process: REST_REQUEST is a constant and the
 * ApiLogin singleton keeps per-request state.
 */
#[RunTestsInSeparateProcesses]
final class ApiLoginTest extends TestCase {

	protected function setUp(): void {
		global $rt2fa_test_options, $rt2fa_test_actions;
		$rt2fa_test_actions = [];
		$rt2fa_test_options[ Settings::OPTION_KEY ] = [
			'enforced_roles'       => [ 'editor' ],
			'enforce_super_admins' => true,
		];

		ApiLogin::instance()->register();
	}

	public function test_non_api_login_passes_through(): void {
		$user = new WP_User( 1, [ 'editor' ], 'eve' );

		self::assertSame( $user, ApiLogin::instance()->block_password_api_login( $user ) );
	}

	public function test_api_password_login_is_blocked(): void {
		define( 'REST_REQUEST', true );
		$user = new WP_User( 1, [ 'editor' ], 'eve' );

		$result = ApiLogin::instance()->block_password_api_login( $user );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'radish_2fa_api_login_disabled', $result->get_error_code() );
	}

	public function test_dummy_basic_header_does_not_bypass_the_block(): void {
		define( 'REST_REQUEST', true );
		$_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode( 'eve:dummy' );
		$user = new WP_User( 1, [ 'editor' ], 'eve' );

		$result = ApiLogin::instance()->block_password_api_login( $user );

		self::assertInstanceOf( WP_Error::class, $result );
	}

	public function test_core_application_password_login_is_allowed(): void {
		define( 'REST_REQUEST', true );
		$user = new WP_User( 1, [ 'editor' ], 'eve' );

		do_action( 'application_password_did_authenticate', $user, [ 'uuid' => 'abc' ] );

		self::assertSame( $user, ApiLogin::instance()->block_password_api_login( $user ) );
	}

	public function test_application_password_of_other_user_does_not_count(): void {
		define( 'REST_REQUEST', true );
		$other = new WP_User( 2, [ 'subscriber' ], 'sam' );
		$user  = new WP_User( 1, [ 'editor' ], 'eve' );

		do_action( 'application_password_did_authenticate', $other, [ 'uuid' => 'abc' ] );

		self::assertInstanceOf( WP_Error::class, ApiLogin::instance()->block_password_api_login( $user ) );
	}

	public function test_user_without_enforced_role_passes_through(): void {
		define( 'REST_REQUEST', true );
		$user = new WP_User( 3, [ 'subscriber' ], 'sam' );

		self::assertSame( $user, ApiLogin::instance()->block_password_api_login( $user ) );
	}
}
