<?php

declare( strict_types=1 );

namespace RadishConcepts\TwoFactor;

/**
 * Plugin (de)activation lifecycle. Multisite-aware: when network-activated,
 * iterates every subsite so each gets its rewrite cache flushed.
 *
 * The actual rule definition lives in Frontend; here we only invalidate the
 * stored version marker so Frontend::maybe_flush_rewrites refreshes them on
 * the next request per site.
 */
final class Activation {

	public const REWRITE_VERSION_KEY = 'radish_2fa_rewrite_version';

	public static function on_activate( bool $network_wide ): void {
		self::for_each_target_site( $network_wide, static function (): void {
			delete_option( self::REWRITE_VERSION_KEY );
		} );
	}

	public static function on_deactivate( bool $network_wide ): void {
		self::for_each_target_site( $network_wide, static function (): void {
			delete_option( self::REWRITE_VERSION_KEY );
			flush_rewrite_rules( false );
		} );
	}

	private static function for_each_target_site( bool $network_wide, callable $callback ): void {
		if ( is_multisite() && $network_wide ) {
			foreach ( get_sites( [ 'number' => 0 ] ) as $site ) {
				switch_to_blog( (int) $site->blog_id );
				$callback();
				restore_current_blog();
			}

			return;
		}

		$callback();
	}
}
