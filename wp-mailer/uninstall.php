<?php
/**
 * Removes plugin data on delete, only if the user opted in under Settings.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$wpm_settings = get_option( 'wpm_settings', array() );

foreach ( array( 'wpm_tick', 'wpm_check_bounces', 'wpm_daily_cleanup' ) as $wpm_hook ) {
	wp_clear_scheduled_hook( $wpm_hook );
}

if ( empty( $wpm_settings['delete_on_uninstall'] ) ) {
	return;
}

global $wpdb;
foreach ( array( 'campaigns', 'sends', 'links', 'events', 'suppressions', 'templates', 'template_revisions' ) as $wpm_table ) {
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'wpm_' . $wpm_table ); // phpcs:ignore WordPress.DB
}
foreach ( array( 'wpm_settings', 'wpm_db_version', 'wpm_token_key', 'wpm_starters_seeded', 'wpm_bounce_last_run', 'wpm_last_error', 'wpm_tick_lock', 'wpm_bounce_lock' ) as $wpm_option ) {
	delete_option( $wpm_option );
}
foreach ( array( 'administrator', 'editor' ) as $wpm_role ) {
	$role = get_role( $wpm_role );
	if ( $role ) {
		$role->remove_cap( 'wpm_manage' );
		$role->remove_cap( 'wpm_manage_templates' );
	}
}
