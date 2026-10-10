<?php
/**
 * Verifier: re-scans after cleanup to confirm residue, and invalidates caches so stale
 * postmeta cache can't serve deleted rows (fixes H3, H6).
 *
 * @package elementor-meta-deduper
 */

namespace EMDD;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Verifier {

	/**
	 * @param int[] $post_ids Posts touched by the run (for targeted cache flush).
	 * @return array{residual_rows:int,residual_posts:int,clean:bool}
	 */
	public static function verify( array $post_ids = array() ): array {
		global $wpdb;

		foreach ( array_unique( array_map( 'intval', $post_ids ) ) as $pid ) {
			if ( $pid > 0 ) {
				clean_post_cache( $pid );
			}
		}
		// Drop any plugin-level meta cache group.
		wp_cache_delete( 'emdd', 'options' );

		$keys = Database::keys_in();
		// residual_rows = excess copies only (c-1 per duplicated post+key), matching Scanner.
		$row  = $wpdb->get_row(
			"SELECT COALESCE(SUM(c-1),0) AS residual_rows, COUNT(DISTINCT post_id) AS residual_posts FROM (
			   SELECT post_id, meta_key, COUNT(*) c FROM {$wpdb->postmeta}
			   WHERE meta_key IN ({$keys}) GROUP BY post_id, meta_key HAVING c>1
			 ) d",
			ARRAY_A
		);

		$residual_rows  = (int) ( $row['residual_rows'] ?? 0 );
		$residual_posts = (int) ( $row['residual_posts'] ?? 0 );

		return array(
			'residual_rows'  => $residual_rows,
			'residual_posts' => $residual_posts,
			'clean'          => 0 === $residual_rows,
		);
	}

	/** Human hint list of follow-up cache actions (not automated: they belong to WP Rocket/Redis/Elementor). */
	public static function followups(): array {
		return array(
			'Regenerate Elementor CSS: Elementor → Tools → Regenerate CSS & Data',
			'Clear WP Rocket cache',
			'Flush Redis object cache (wp redis flush) if enabled',
		);
	}
}
