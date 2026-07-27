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
	 * Decide where a request whose 2FA token can no longer be resolved should go.
	 *
	 * An unresolvable token is not automatically a failed sign-in. It is usually a
	 * repeat of a request that already succeeded (double-clicked submit, browser
	 * re-sending the POST on back or reload, restored tab), or a stale link opened
	 * by someone whose session is alive. Rendering "session expired" in those cases
	 * reports a failure that never happened.
	 *
	 * @param array<string,mixed>|null $receipt      Receipt of a completed flow for this token, if any.
	 * @param bool                     $is_logged_in Whether the request carries a valid session.
	 *
	 * @return string|null Redirect target, or null when the visitor really must sign in again.
	 */
	public static function after_stale_token( ?array $receipt, bool $is_logged_in ): ?string {
		if ( null !== $receipt ) {
			return self::ensure( (string) ( $receipt['redirect_to'] ?? '' ) );
		}

		if ( $is_logged_in ) {
			return self::ensure( '' );
		}

		return null;
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
