<?php
/**
 * Plugin Name:       WP Mailer
 * Description:       Mailing lists inside WordPress: pick an audience from your own table, design HTML templates, send campaigns over SMTP and track opens, clicks and bounces.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      8.1
 * Author:            WP Mailer
 * License:           GPL-2.0-or-later
 * Text Domain:       wp-mailer
 */

defined( 'ABSPATH' ) || exit;

define( 'WPM_VERSION', '1.0.0' );
define( 'WPM_DB_VERSION', '1' );
define( 'WPM_FILE', __FILE__ );
define( 'WPM_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPM_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	static function ( string $class ): void {
		if ( ! str_starts_with( $class, 'WPM\\' ) ) {
			return;
		}
		$path = WPM_DIR . 'src/' . str_replace( '\\', '/', substr( $class, 4 ) ) . '.php';
		if ( is_readable( $path ) ) {
			require $path;
		}
	}
);

register_activation_hook( __FILE__, array( \WPM\Install\Schema::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \WPM\Plugin::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( \WPM\Plugin::class, 'boot' ) );
