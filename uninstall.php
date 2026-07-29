<?php
/**
 * Uninstall cleanup for Elementor Meta Deduper.
 * Removes plugin options + scheduled monitor. Never touches site content or manifests
 * already written to uploads (those are the operator's records).
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Settings + logs (v1 + v2 keys).
$options = array(
	'emdd_keep_mode',
	'emdd_alert_email',
	'emdd_alert_threshold',
	'emdd_last_log',       // v1
	'emdd_audit_log',      // v2
	'emdd_monitor_history',
	'emdd_monitor_last_alert_hash',
);
foreach ( $options as $opt ) {
	delete_option( $opt );
}

// Per-run progress options (emdd_progress_<uuid>) + run lock transient.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'emdd_progress_%'" );
delete_transient( 'emdd_run_lock' );

// Unschedule both AS and WP-Cron variants.
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'emdd_weekly_guard', array(), 'emdd' );
}
$ts = wp_next_scheduled( 'emdd_weekly_guard' );
if ( $ts ) {
	wp_unschedule_event( $ts, 'emdd_weekly_guard' );
}
