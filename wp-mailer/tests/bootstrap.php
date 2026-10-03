<?php
/**
 * Minimal WordPress stand-ins so the plugin's pure classes can be tested
 * without a WordPress install: `php tests/run.php`.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'WPM_DIR', dirname( __DIR__ ) . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'ARRAY_A', 'ARRAY_A' );

spl_autoload_register(
	static function ( string $class ): void {
		if ( str_starts_with( $class, 'WPM\\' ) ) {
			require WPM_DIR . 'src/' . str_replace( '\\', '/', substr( $class, 4 ) ) . '.php';
		}
	}
);

$GLOBALS['wpm_test_options'] = array();
function get_option( $key, $default = false ) {
	return $GLOBALS['wpm_test_options'][ $key ] ?? $default;
}
function wp_json_encode( $data ) {
	return json_encode( $data );
}
function __( $text ) {
	return $text;
}
function current_time() {
	return gmdate( 'Y-m-d H:i:s' );
}

/** Fake $wpdb: records queries, returns nothing. Enough for "send not found" paths. */
final class Fake_WPDB {
	public string $prefix = 'wp_';
	public array $queries = array();
	public function prepare( $sql, ...$args ) {
		return $sql;
	}
	public function get_row( $sql ) {
		$this->queries[] = $sql;
		return null;
	}
	public function __call( $name, $args ) {
		$this->queries[] = $name;
		return null;
	}
}
$GLOBALS['wpdb'] = new Fake_WPDB();
