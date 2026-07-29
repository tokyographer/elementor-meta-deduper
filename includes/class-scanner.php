<?php
/**
 * Scanner: read-only, ACCURATE totals. LIMIT is used only for the display sample,
 * never to derive totals (fixes C2).
 *
 * @package elementor-meta-deduper
 */

namespace EMDD;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scanner {

	/**
	 * Full totals over the entire dataset. Every figure comes from its own aggregate query.
	 *
	 * @return array
	 */
	public static function totals(): array {
		global $wpdb;
		$keys = Database::keys_in();
		$agg  = ( 'oldest' === get_option( 'emdd_keep_mode', 'newest' ) ) ? 'MIN' : 'MAX';

		// Excess rows + redundant bytes across ALL affected posts/keys (not limited).
		$row = $wpdb->get_row(
			"SELECT COUNT(*) AS redundant_rows,
			        COALESCE(SUM(LENGTH(pm.meta_value)),0) AS redundant_bytes
			 FROM {$wpdb->postmeta} pm
			 JOIN (SELECT post_id, meta_key, {$agg}(meta_id) AS keep
			       FROM {$wpdb->postmeta} WHERE meta_key IN ({$keys})
			       GROUP BY post_id, meta_key) k
			   ON pm.post_id=k.post_id AND pm.meta_key=k.meta_key
			 WHERE pm.meta_key IN ({$keys}) AND pm.meta_id<>k.keep",
			ARRAY_A
		);

		$affected_posts = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM (
			   SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ({$keys})
			   GROUP BY post_id, meta_key HAVING COUNT(*)>1
			 ) t"
		);

		// Per-key breakdown (full).
		$by_key = $wpdb->get_results(
			"SELECT meta_key,
			        COUNT(*) AS total_rows,
			        COUNT(DISTINCT post_id) AS posts,
			        COUNT(*)-COUNT(DISTINCT post_id) AS excess_rows,
			        COALESCE(SUM(LENGTH(meta_value)),0) AS bytes
			 FROM {$wpdb->postmeta} WHERE meta_key IN ({$keys})
			 GROUP BY meta_key ORDER BY excess_rows DESC",
			ARRAY_A
		);

		$postmeta_mb = (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ROUND((data_length+index_length)/1048576,1) FROM information_schema.tables
				 WHERE table_schema=DATABASE() AND table_name=%s",
				$wpdb->postmeta
			)
		);

		return array(
			'redundant_rows'  => (int) ( $row['redundant_rows'] ?? 0 ),
			'redundant_bytes' => (int) ( $row['redundant_bytes'] ?? 0 ),
			'affected_posts'  => $affected_posts,
			'by_key'          => $by_key ?: array(),
			'postmeta_mb'     => $postmeta_mb,
			'keep_mode'       => ( 'oldest' === get_option( 'emdd_keep_mode', 'newest' ) ) ? 'oldest' : 'newest',
		);
	}

	/**
	 * Display sample ONLY. LIMIT lives here and nowhere else.
	 *
	 * @param int $limit
	 * @return array
	 */
	public static function sample( int $limit = 50 ): array {
		global $wpdb;
		$limit = max( 1, min( 500, $limit ) );
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, COUNT(*) AS copies,
				        COUNT(DISTINCT MD5(meta_value)) AS distinct_vals,
				        ROUND(SUM(LENGTH(meta_value))/1024,0) AS kb
				 FROM {$wpdb->postmeta} WHERE meta_key='_elementor_data'
				 GROUP BY post_id HAVING copies>1 ORDER BY SUM(LENGTH(meta_value)) DESC LIMIT %d",
				$limit
			),
			ARRAY_A
		) ?: array();
	}

	/** IDs of all affected posts for _elementor_data (full, for planning/batching). */
	public static function affected_post_ids( string $meta_key = '_elementor_data' ): array {
		global $wpdb;
		$meta_key = in_array( $meta_key, Database::KEYS, true ) ? $meta_key : '_elementor_data';
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key=%s
				 GROUP BY post_id HAVING COUNT(*)>1 ORDER BY post_id",
				$meta_key
			)
		);
		return array_map( 'intval', $ids ?: array() );
	}
}
