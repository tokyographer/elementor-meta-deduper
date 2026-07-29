<?php
/**
 * Recurrence monitor: scheduled SCAN + NOTIFY only. Never plans or deletes.
 * Tracks history, alerts on threshold/growth, suppresses duplicate alerts.
 * Prefers Action Scheduler (present with WooCommerce) over WP-Cron when available.
 *
 * @package elementor-meta-deduper
 */

namespace EMDD;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Monitor {

	public const HOOK      = 'emdd_weekly_guard';
	private const HISTORY  = 'emdd_monitor_history';
	private const LASTHASH = 'emdd_monitor_last_alert_hash';

	/** Schedule via Action Scheduler if available, else WP-Cron. */
	public static function schedule(): void {
		if ( function_exists( 'as_has_scheduled_action' ) ) {
			if ( ! as_has_scheduled_action( self::HOOK ) ) {
				as_schedule_recurring_action( time() + DAY_IN_SECONDS, WEEK_IN_SECONDS, self::HOOK, array(), 'emdd' );
			}
			return;
		}
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'emdd_weekly', self::HOOK );
		}
	}

	public static function unschedule(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, array(), 'emdd' );
		}
		$ts = wp_next_scheduled( self::HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::HOOK );
		}
	}

	/** The scheduled callback: scan + analyze + maybe notify. NEVER deletes. */
	public static function run(): void {
		$totals   = Scanner::totals();
		$current  = array(
			'rows'  => (int) $totals['redundant_rows'],
			'posts' => (int) $totals['affected_posts'],
			'bytes' => (int) $totals['redundant_bytes'],
			'time'  => current_time( 'mysql' ),
		);

		$history = get_option( self::HISTORY, array() );
		$prev    = is_array( $history ) && $history ? end( $history ) : null;

		$history[] = $current;
		$history   = array_slice( (array) $history, -26 ); // ~6 months of weekly points
		update_option( self::HISTORY, $history, false );

		$threshold = (int) get_option( 'emdd_alert_threshold', 1 ); // rows
		if ( $current['rows'] < $threshold ) {
			return;
		}

		// Suppress identical repeat alerts.
		$hash = md5( $current['rows'] . '|' . $current['posts'] );
		if ( get_option( self::LASTHASH ) === $hash ) {
			return;
		}
		update_option( self::LASTHASH, $hash, false );

		$delta_rows = $prev ? ( $current['rows'] - (int) $prev['rows'] ) : $current['rows'];
		$to      = get_option( 'emdd_alert_email', get_option( 'admin_email' ) );
		$subject = sprintf( '[%s] Elementor meta duplication detected (%d rows)', wp_specialchars_decode( get_bloginfo( 'name' ) ), $current['rows'] );
		$body    = sprintf(
			"The Elementor Meta Deduper monitor found duplicate _elementor_data rows.\n\n" .
			"Current : %d rows across %d posts (%.1f MB redundant)\n" .
			"Previous: %s\n" .
			"Change  : %+d rows since last check\n\n" .
			"This monitor only reports — it never deletes. If this is growing, the WPML × Elementor\n" .
			"sync bug is still active; update SitePress + WPML Page Builders glue + Elementor.\n\n" .
			"Review & clean (WP-CLI recommended for large runs):\n  %s\n  wp elementor-dedup scan\n",
			$current['rows'], $current['posts'], $current['bytes'] / 1048576,
			$prev ? sprintf( '%d rows across %d posts', (int) $prev['rows'], (int) $prev['posts'] ) : 'n/a',
			$delta_rows,
			admin_url( 'tools.php?page=elementor-meta-deduper' )
		);
		wp_mail( $to, $subject, $body );
	}
}
