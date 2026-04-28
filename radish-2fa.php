<?php
/**
 * Plugin Name:       Radish 2FA
 * Plugin URI:        https://github.com/radishconcepts/radish-wp-2fa
 * Description:       Enforceable, frontend-first two-factor authentication (TOTP) for WordPress, with role-based hard enforcement.
 * Version:           0.1.1
 * Requires at least: 6.2
 * Requires PHP:      8.1
 * Author:            Radish Concepts
 * Author URI:        https://www.radishconcepts.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Text Domain:       radish-2fa
 * Domain Path:       /languages
 * Network:           true
 */

declare( strict_types=1 );

namespace RadishConcepts\TwoFactor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'RADISH_2FA_VERSION', '0.1.1' );
define( 'RADISH_2FA_FILE', __FILE__ );
define( 'RADISH_2FA_DIR', plugin_dir_path( __FILE__ ) );
define( 'RADISH_2FA_URL', plugin_dir_url( __FILE__ ) );

$autoload = RADISH_2FA_DIR . 'vendor/autoload.php';
if ( is_readable( $autoload ) ) {
	require_once $autoload;
}

spl_autoload_register( static function ( string $class ): void {
	$prefix = __NAMESPACE__ . '\\';
	if ( ! str_starts_with( $class, $prefix ) ) {
		return;
	}

	$relative = substr( $class, strlen( $prefix ) );
	$path     = RADISH_2FA_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';

	if ( is_readable( $path ) ) {
		require_once $path;
	}
} );

register_activation_hook( __FILE__, static function ( $network_wide ): void {
	Activation::on_activate( (bool) $network_wide );
} );

register_deactivation_hook( __FILE__, static function ( $network_wide ): void {
	Activation::on_deactivate( (bool) $network_wide );
} );

add_action( 'plugins_loaded', static function (): void {
	Plugin::instance()->boot();
} );
