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

		return (bool) array_intersect( (array) $user->roles, $enforced );
	}

	private function user_is_disabled_via_constant( WP_User $user ): bool {
		if ( ! defined( 'RADISH_2FA_DISABLE_FOR_USER_ID' ) ) {
			return false;
		}

		$disabled = (array) RADISH_2FA_DISABLE_FOR_USER_ID;

		return in_array( (int) $user->ID, array_map( 'intval', $disabled ), true );
	}
}
