<?php

declare( strict_types=1 );

namespace RadishConcepts\TwoFactor\Auth;

/**
 * Resolves a safe, non-empty post-login redirect URL.
 *
 * WordPress' wp_validate_redirect() only substitutes its fallback when a URL
 * has a *disallowed host*; an empty string slips through unchanged. Login
 * forms that omit a redirect target — notably WooCommerce's My Account form,
 * which uses a `redirect` field rather than `redirect_to` — therefore yielded
 * an empty redirect, and wp_safe_redirect( '' ) emits no Location header at
 * all, leaving the user on a blank page after an otherwise successful sign-in.
 */
final class RedirectTarget {

	/**
	 * Request keys to honour, in priority order: wp-login.php uses `redirect_to`,
	 * WooCommerce (and several other front-end forms) use `redirect`.
	 *
	 * @var string[]
	 */
	private const REQUEST_KEYS = [ 'redirect_to', 'redirect' ];

	/**
	 * Pick the redirect target for a login request.
	 *
	 * @param array<string,mixed> $request     Request data (typically $_REQUEST).
	 * @param bool                $is_wp_login Whether the request was served by wp-login.php.
	 */
	public static function resolve( array $request, bool $is_wp_login ): string {
		foreach ( self::REQUEST_KEYS as $key ) {
			if ( empty( $request[ $key ] ) ) {
				continue;
			}

			$candidate = wp_validate_redirect( (string) wp_unslash( $request[ $key ] ), '' );
			if ( '' !== $candidate ) {
				return $candidate;
			}
		}

		// No usable target: admin sign-ins land in wp-admin, while front-end
		// sign-ins (e.g. WooCommerce My Account) return to the public site.
		return $is_wp_login ? admin_url() : home_url( '/' );
	}

	/**
	 * Guarantee a non-empty, validated redirect immediately before emitting it.
	 * Defends every redirect against wp_validate_redirect()'s empty pass-through.
	 */
	public static function ensure( string $target ): string {
		$validated = wp_validate_redirect( $target, '' );

		return '' !== $validated ? $validated : home_url( '/' );
	}

	private function __construct() {}
}
