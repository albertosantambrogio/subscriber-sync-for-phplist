<?php
/**
 * Uninstall: remove the table, the options and the cron events.
 * Subscribers already in phpList are not touched.
 *
 * @package SubscriberSyncForPhpList
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$wpss_table = $wpdb->prefix . 'wpss_jobs';
$wpdb->query( "DROP TABLE IF EXISTS {$wpss_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange

foreach ( array( 'wpss_settings', 'wpss_secret', 'wpss_ping_cache', 'wpss_last_digest', 'wpss_db_version' ) as $wpss_option ) {
	delete_option( $wpss_option );
}

foreach ( array( 'wpss_run_queue', 'wpss_run_queue_recurring', 'wpss_digest', 'wpss_purge' ) as $wpss_hook ) {
	wp_clear_scheduled_hook( $wpss_hook );
}
