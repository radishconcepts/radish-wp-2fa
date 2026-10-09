<?php

declare( strict_types=1 );

namespace RadishConcepts\TwoFactor\Tests;

use PHPUnit\Framework\TestCase;
use RadishConcepts\TwoFactor\Roles;
use RadishConcepts\TwoFactor\Admin\Settings;
use WP_User;

final class RolesTest extends TestCase {

	protected function setUp(): void {
		global $rt2fa_test_options, $rt2fa_test_super_admin, $rt2fa_test_multisite, $rt2fa_test_blogs, $rt2fa_test_user_meta;
		$rt2fa_test_options     = [];
		$rt2fa_test_super_admin = false;
		$rt2fa_test_multisite   = false;
		$rt2fa_test_blogs       = [];
		$rt2fa_test_user_meta   = [];
	}

	protected function tearDown(): void {
		global $rt2fa_test_multisite, $rt2fa_test_blogs, $rt2fa_test_user_meta;
		$rt2fa_test_multisite = false;
		$rt2fa_test_blogs     = [];
		$rt2fa_test_user_meta = [];
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

	public function test_multisite_enforced_role_on_other_site_requires_2fa(): void {
		$this->set_settings( [ 'enforced_roles' => [ 'administrator' ] ] );
		$this->enable_multisite();
		$this->add_site_role( 7, 1, [] );
		$this->add_site_role( 7, 2, [ 'administrator' ] );

		// Logging in on site 1, where the user has no role at all.
		$user = new WP_User( 7, [], 'cees' );

		self::assertTrue( Roles::instance()->user_requires_2fa( $user ) );
	}

	public function test_multisite_only_unenforced_roles_does_not_require_2fa(): void {
		$this->set_settings( [ 'enforced_roles' => [ 'administrator', 'shop_manager' ] ] );
		$this->enable_multisite();
		$this->add_site_role( 8, 1, [ 'customer' ] );
		$this->add_site_role( 8, 2, [ 'customer' ] );
		$this->add_site_role( 8, 3, [ 'customer' ] );

		$user = new WP_User( 8, [ 'customer' ], 'klant' );

		self::assertFalse( Roles::instance()->user_requires_2fa( $user ) );
	}

	public function test_multisite_enforced_role_on_archived_site_requires_2fa(): void {
		$this->set_settings( [ 'enforced_roles' => [ 'editor' ] ] );
		$this->enable_multisite();
		$this->add_site_role( 9, 1, [ 'subscriber' ] );
		$this->add_site_role( 9, 4, [ 'editor' ], true );

		$user = new WP_User( 9, [ 'subscriber' ], 'archief' );

		self::assertTrue( Roles::instance()->user_requires_2fa( $user ) );
	}

	public function test_multisite_ignores_revoked_capability_flags(): void {
		$this->set_settings( [ 'enforced_roles' => [ 'editor' ] ] );
		$this->enable_multisite();
		$this->add_site_role( 10, 1, [ 'subscriber' ] );
		$this->add_site_role( 10, 2, [ 'editor' => false, 'subscriber' => true ] );

		$user = new WP_User( 10, [ 'subscriber' ], 'revoked' );

		self::assertFalse( Roles::instance()->user_requires_2fa( $user ) );
	}

	public function test_multisite_current_site_role_still_counts(): void {
		$this->set_settings( [ 'enforced_roles' => [ 'editor' ] ] );
		$this->enable_multisite();

		// No network membership recorded, only the in-memory role on this site.
		$user = new WP_User( 11, [ 'editor' ], 'eve' );

		self::assertTrue( Roles::instance()->user_requires_2fa( $user ) );
	}

	private function enable_multisite(): void {
		global $rt2fa_test_multisite;
		$rt2fa_test_multisite = true;
	}

	/**
	 * Give a user roles on a network site, the way WordPress stores them.
	 *
	 * @param array<int|string,mixed> $roles Role keys, or a raw capabilities map.
	 */
	private function add_site_role( int $user_id, int $blog_id, array $roles, bool $archived = false ): void {
		global $rt2fa_test_blogs, $wpdb;

		$rt2fa_test_blogs[ $blog_id ]['members'][] = $user_id;
		$rt2fa_test_blogs[ $blog_id ]['archived']  = $archived;

		$caps = array_is_list( $roles ) ? array_fill_keys( $roles, true ) : $roles;
		update_user_meta( $user_id, $wpdb->get_blog_prefix( $blog_id ) . 'capabilities', $caps );
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
