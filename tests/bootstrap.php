<?php

declare( strict_types=1 );

/**
 * Lightweight WP-function stubs so we can unit-test the pure-logic classes
 * without spinning up the full WordPress test framework. Only the functions
 * actually called by the classes under test are stubbed.
 */

require_once __DIR__ . '/../vendor/autoload.php';

if ( ! defined( 'AUTH_KEY' ) ) {
	define( 'AUTH_KEY', str_repeat( 'A', 64 ) );
}
if ( ! defined( 'SECURE_AUTH_KEY' ) ) {
	define( 'SECURE_AUTH_KEY', str_repeat( 'B', 64 ) );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'RADISH_2FA_DIR' ) ) {
	define( 'RADISH_2FA_DIR', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'RADISH_2FA_VERSION' ) ) {
	define( 'RADISH_2FA_VERSION', '0.1.0-test' );
}

/**
 * In-memory site_transient store used by Nonce tests.
 *
 * @var array<string,mixed>
 */
global $rt2fa_test_transients, $rt2fa_test_options, $rt2fa_test_user_meta, $rt2fa_test_super_admin, $rt2fa_test_actions;
$rt2fa_test_transients   = [];
$rt2fa_test_options      = [];
$rt2fa_test_user_meta    = [];
$rt2fa_test_super_admin  = false;
$rt2fa_test_actions      = [];

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		global $rt2fa_test_actions;
		$rt2fa_test_actions[] = [
			'hook'     => $hook,
			'callback' => $callback,
			'priority' => $priority,
		];

		return true;
	}
}

if ( ! function_exists( 'set_site_transient' ) ) {
	function set_site_transient( string $key, $value, int $ttl = 0 ): bool {
		global $rt2fa_test_transients;
		$rt2fa_test_transients[ $key ] = $value;

		return true;
	}
}

if ( ! function_exists( 'get_site_transient' ) ) {
	function get_site_transient( string $key ) {
		global $rt2fa_test_transients;

		return $rt2fa_test_transients[ $key ] ?? false;
	}
}

if ( ! function_exists( 'delete_site_transient' ) ) {
	function delete_site_transient( string $key ): bool {
		global $rt2fa_test_transients;
		unset( $rt2fa_test_transients[ $key ] );

		return true;
	}
}

if ( ! function_exists( 'is_multisite' ) ) {
	function is_multisite(): bool {
		return false;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $key, $default = false ) {
		global $rt2fa_test_options;

		return $rt2fa_test_options[ $key ] ?? $default;
	}
}

if ( ! function_exists( 'get_site_option' ) ) {
	function get_site_option( string $key, $default = false ) {
		return get_option( $key, $default );
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $key, $value, bool $autoload = true ): bool {
		global $rt2fa_test_options;
		$rt2fa_test_options[ $key ] = $value;

		return true;
	}
}

if ( ! function_exists( 'wp_parse_args' ) ) {
	function wp_parse_args( $args, array $defaults = [] ): array {
		if ( ! is_array( $args ) ) {
			$args = [];
		}

		return array_merge( $defaults, $args );
	}
}

if ( ! function_exists( 'is_super_admin' ) ) {
	function is_super_admin( $user_id = false ): bool {
		global $rt2fa_test_super_admin;

		return (bool) $rt2fa_test_super_admin;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value ) {
		return $value;
	}
}

if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( string $key = '' ): string {
		return 'Test Site';
	}
}

if ( ! function_exists( 'home_url' ) ) {
	function home_url( string $path = '' ): string {
		return 'https://example.test' . $path;
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( string $url, int $component = -1 ) {
		return parse_url( $url, $component );
	}
}

if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( $key, $value = null, $url = '' ): string {
		if ( is_array( $key ) ) {
			$args = $key;
			$url  = $value ?? '';
		} else {
			$args = [ $key => $value ];
		}

		$separator = str_contains( $url, '?' ) ? '&' : '?';
		$query     = http_build_query( $args );

		return $url . $separator . $query;
	}
}

if ( ! function_exists( 'wp_generate_password' ) ) {
	function wp_generate_password( int $length = 12, bool $special_chars = true, bool $extra_special = false ): string {
		$chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
		$out   = '';
		for ( $i = 0; $i < $length; $i++ ) {
			$out .= $chars[ random_int( 0, strlen( $chars ) - 1 ) ];
		}

		return $out;
	}
}

if ( ! function_exists( 'wp_hash_password' ) ) {
	function wp_hash_password( string $password ): string {
		return password_hash( $password, PASSWORD_BCRYPT, [ 'cost' => 4 ] );
	}
}

if ( ! function_exists( 'wp_check_password' ) ) {
	function wp_check_password( string $password, string $hash ): bool {
		return password_verify( $password, $hash );
	}
}

// User-meta + WP_User stubs for Roles tests.
if ( ! class_exists( 'WP_User' ) ) {
	class WP_User {
		public int $ID;
		public array $roles;
		public string $user_login;

		public function __construct( int $id, array $roles = [], string $login = '' ) {
			$this->ID         = $id;
			$this->roles      = $roles;
			$this->user_login = $login;
		}
	}
}
