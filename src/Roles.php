<?php

declare( strict_types=1 );

namespace RadishConcepts\TwoFactor;

use RadishConcepts\TwoFactor\Admin\Settings;
use WP_User;

final class Roles {

	private static ?self $instance = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	public function user_requires_2fa( WP_User $user ): bool {
		if ( $this->user_is_disabled_via_constant( $user ) ) {
			return false;
		}

		$settings = Settings::instance()->get();

		if ( is_multisite() && ! empty( $settings['enforce_super_admins'] ) && is_super_admin( $user->ID ) ) {
			return true;
		}

		$enforced = (array) ( $settings['enforced_roles'] ?? [] );
		if ( empty( $enforced ) ) {
			return false;
		}

		return (bool) array_intersect( $this->roles_on_network( $user ), $enforced );
	}

	/**
	 * Collect the user's roles on every site of the network they belong to.
	 *
	 * `$user->roles` only holds the roles on the current site, while the auth
	 * cookie is valid network-wide. Checking the current site alone would let
	 * a user skip 2FA by logging in on a site where they have no enforced role.
	 * Archived, spammed and deleted sites are included on purpose: a role there
	 * still triggers 2FA.
	 *
	 * @param WP_User $user User to look up.
	 *
	 * @return string[] Role keys, unique.
	 */
	private function roles_on_network( WP_User $user ): array {
		$roles = (array) $user->roles;

		if ( ! is_multisite() ) {
			return $roles;
		}

		global $wpdb;

		foreach ( get_blogs_of_user( $user->ID, true ) as $blog ) {
			$caps = get_user_meta( $user->ID, $wpdb->get_blog_prefix( $blog->userblog_id ) . 'capabilities', true );
			if ( is_array( $caps ) ) {
				$roles = array_merge( $roles, array_keys( array_filter( $caps ) ) );
			}
		}

		return array_values( array_unique( $roles ) );
	}

	private function user_is_disabled_via_constant( WP_User $user ): bool {
		if ( ! defined( 'RADISH_2FA_DISABLE_FOR_USER_ID' ) ) {
			return false;
		}

		$disabled = (array) RADISH_2FA_DISABLE_FOR_USER_ID;

		return in_array( (int) $user->ID, array_map( 'intval', $disabled ), true );
	}
}
