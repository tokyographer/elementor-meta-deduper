<?php
/**
 * Plugin Name:       Elementor Meta Deduper
 * Plugin URI:        https://github.com/OWNER/elementor-meta-deduper
 * Description:       Detects and safely removes duplicate single-value Elementor postmeta rows caused by the WPML × Elementor translation-sync bug. Fail-loud, batched, transactional cleanup with manifest export, verification, evidence-based optimize, audit log, and a notify-only recurrence monitor. WP-CLI-first for large runs.
 * Version:           2.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Elementor Meta Deduper Contributors
 * License:           GPL-2.0-or-later
 * Text Domain:       elementor-meta-deduper
 *
 * Architecture: thin bootstrap → includes/ components (see docs/ARCHITECTURE_PROPOSAL.md).
 * Safety model, retention policy, and defect history: see docs/.
 */

namespace EMDD;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EMDD_VERSION', '2.0.0' );
define( 'EMDD_DIR', plugin_dir_path( __FILE__ ) );

/** Load components; fail safe (admin notice) rather than fatal if a file is missing. */
$emdd_files = array(
	'class-database.php',
	'class-scanner.php',
	'class-analyzer.php',
	'class-planner.php',
	'class-export.php',
	'class-executor.php',
	'class-verifier.php',
	'class-optimizer.php',
	'class-audit.php',
	'class-monitor.php',
	'class-admin.php',
	'class-cli.php',
);
$emdd_missing = array();
foreach ( $emdd_files as $f ) {
	$path = EMDD_DIR . 'includes/' . $f;
	if ( is_readable( $path ) ) {
		require_once $path;
	} else {
		$emdd_missing[] = $f;
	}
}
if ( $emdd_missing ) {
	add_action( 'admin_notices', static function () use ( $emdd_missing ) {
		echo '<div class="notice notice-error"><p><strong>Elementor Meta Deduper:</strong> missing components: '
			. esc_html( implode( ', ', $emdd_missing ) ) . '. Plugin disabled.</p></div>';
	} );
	return;
}

// ── Weekly schedule name for the WP-Cron fallback ────────────────────────────
add_filter( 'cron_schedules', static function ( $s ) {
	$s['emdd_weekly'] = array( 'interval' => WEEK_IN_SECONDS, 'display' => __( 'Once Weekly', 'elementor-meta-deduper' ) );
	return $s;
} );

// ── Activation / deactivation ────────────────────────────────────────────────
register_activation_hook( __FILE__, static function () {
	if ( false === get_option( 'emdd_alert_email', false ) ) {
		update_option( 'emdd_alert_email', get_option( 'admin_email' ) );
	}
	if ( false === get_option( 'emdd_keep_mode', false ) ) {
		update_option( 'emdd_keep_mode', 'newest' );
	}
	if ( false === get_option( 'emdd_alert_threshold', false ) ) {
		update_option( 'emdd_alert_threshold', 1 );
	}
	Monitor::schedule();
} );

register_deactivation_hook( __FILE__, static function () {
	Monitor::unschedule();
} );

// ── Recurrence monitor (notify-only; never deletes) ──────────────────────────
add_action( Monitor::HOOK, array( Monitor::class, 'run' ) );

// ── Admin ────────────────────────────────────────────────────────────────────
add_action( 'admin_menu', array( Admin::class, 'menu' ) );

// ── WP-CLI ────────────────────────────────────────────────────────────────────
if ( defined( 'WP_CLI' ) && \WP_CLI ) {
	\WP_CLI::add_command( 'elementor-dedup', CLI::class );
}
