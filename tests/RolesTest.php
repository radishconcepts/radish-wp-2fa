<?php

declare( strict_types=1 );

namespace RadishConcepts\TwoFactor\Tests;

use PHPUnit\Framework\TestCase;
use RadishConcepts\TwoFactor\Roles;
use RadishConcepts\TwoFactor\Admin\Settings;
use WP_User;

final class RolesTest extends TestCase {

	protected function setUp(): void {
		global $rt2fa_test_options, $rt2fa_test_super_admin;
		$rt2fa_test_options     = [];
		$rt2fa_test_super_admin = false;
	}

	public function test_user_with_enforced_role_requires_2fa(): void {
		$this->set_settings( [ 'enforced_roles' => [ 'editor' ] ] );

		$user = new WP_User( 1, [ 'editor' ], 'eve' );

		self::assertTrue( Roles::instance()->user_requires_2fa( $user ) );
	}

	public function test_user_without_enforced_role_does_not_require_2fa(): void {
		$this->set_settings( [ 'enforced_roles' => [ 'editor' ] ] );

		$user = new WP_User( 1, [ 'subscriber' ], 'sam' );

		self::assertFalse( Roles::instance()->user_requires_2fa( $user ) );
	}

	public function test_empty_enforcement_means_no_users_required(): void {
		$this->set_settings( [ 'enforced_roles' => [] ] );

		$user = new WP_User( 1, [ 'administrator' ], 'admin' );

		self::assertFalse( Roles::instance()->user_requires_2fa( $user ) );
	}

	public function test_user_with_any_overlapping_role_is_required(): void {
		$this->set_settings( [ 'enforced_roles' => [ 'editor', 'shop_manager' ] ] );

		$user = new WP_User( 1, [ 'subscriber', 'shop_manager' ], 'sm' );

		self::assertTrue( Roles::instance()->user_requires_2fa( $user ) );
	}

	public function test_disable_constant_overrides_enforcement(): void {
		$this->set_settings( [ 'enforced_roles' => [ 'editor' ] ] );

		// Define the constant for the duration of this test only.
		if ( ! defined( 'RADISH_2FA_DISABLE_FOR_USER_ID' ) ) {
			define( 'RADISH_2FA_DISABLE_FOR_USER_ID', 99 );
		}

		$disabled = new WP_User( 99, [ 'editor' ], 'panic' );
		$normal   = new WP_User( 1, [ 'editor' ], 'eve' );

		self::assertFalse( Roles::instance()->user_requires_2fa( $disabled ), 'Constant must override' );
		self::assertTrue( Roles::instance()->user_requires_2fa( $normal ), 'Other users still enforced' );
	}

	private function set_settings( array $settings ): void {
		global $rt2fa_test_options;
		$rt2fa_test_options[ Settings::OPTION_KEY ] = array_merge(
			[
				'enforced_roles'       => [],
				'enforce_super_admins' => true,
			],
			$settings
		);
	}
}
