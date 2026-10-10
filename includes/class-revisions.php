<?php
/**
 * Revisions cleaner. Deletes post revisions (and their meta + term relationships) in
 * batches via the fail-loud DB layer. Kept SEPARATE from the dedup path — it is a
 * distinct, explicitly-invoked action, never run from cron.
 *
 * Elementor stores a full layout-JSON copy per revision, so revision buildup is a major
 * source of wp_postmeta bloat independent of the WPML duplication bug. Autosaves
 * (post_name "<id>-autosave-v1") are skipped: they can hold unsaved editor work.
 *
 * @package elementor-meta-deduper
 */

namespace EMDD;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Revisions {

	/**
	 * Revisions that are safe to delete: real revisions, not in-progress autosaves.
	 * Not passed through $wpdb->prepare() (the literal % would be treated as a placeholder).
	 */
	private static function where( string $alias = '' ): string {
		$a = $alias ? $alias . '.' : '';
		return "{$a}post_type='revision' AND {$a}post_name NOT LIKE '%-autosave-v1'";
	}

	/**
	 * Read-only scan of revision bloat.
	 *
	 * @return array{count:int,elementor_rows:int,elementor_mb:float,total_meta_mb:float}
	 */
	public static function scan(): array {
		global $wpdb;

		$where   = self::where();
		$where_p = self::where( 'p' );
		$count   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE {$where}" );

		$row = $wpdb->get_row(
			"SELECT COUNT(*) AS rows_n, COALESCE(SUM(LENGTH(pm.meta_value)),0) AS bytes
			 FROM {$wpdb->postmeta} pm
			 JOIN {$wpdb->posts} p ON p.ID=pm.post_id
			 WHERE {$where_p} AND pm.meta_key='_elementor_data'",
			ARRAY_A
		);

		$total_meta_bytes = (int) $wpdb->get_var(
			"SELECT COALESCE(SUM(LENGTH(pm.meta_value)),0)
			 FROM {$wpdb->postmeta} pm
			 JOIN {$wpdb->posts} p ON p.ID=pm.post_id
			 WHERE {$where_p}"
		);

		return array(
			'count'          => $count,
			'elementor_rows' => (int) ( $row['rows_n'] ?? 0 ),
			'elementor_mb'   => round( ( (int) ( $row['bytes'] ?? 0 ) ) / 1048576, 1 ),
			'total_meta_mb'  => round( $total_meta_bytes / 1048576, 1 ),
		);
	}

	/**
	 * Delete revisions in batches. Each batch is its own transaction; a failed statement
	 * throws → that batch rolls back and the run stops. Idempotent and resumable-by-repeat
	 * (each call operates on whatever revisions currently remain).
	 *
	 * @param array $opts { batch_size:int (posts per batch), max_delete:int (posts cap) }
	 * @return array{deleted:int,batches:int,stopped:bool,error:?string,remaining:int}
	 */
	public static function clean( array $opts = array() ): array {
		global $wpdb;
		$batch_size = max( 1, (int) ( $opts['batch_size'] ?? 200 ) );
		$max_delete = max( 1, (int) ( $opts['max_delete'] ?? 5000 ) );

		$result = array( 'deleted' => 0, 'batches' => 0, 'stopped' => false, 'error' => null, 'remaining' => 0 );
		$where  = self::where();

		if ( ! Database::acquire_lock() ) {
			$result['stopped'] = true;
			$result['error']   = 'Another cleanup run holds the lock; aborting.';
			return $result;
		}

		try {
			while ( $result['deleted'] < $max_delete ) {
				$limit = (int) min( $batch_size, $max_delete - $result['deleted'] );
				$ids   = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE {$where} ORDER BY ID LIMIT {$limit}" );
				$ids = array_map( 'intval', $ids ?: array() );
				if ( ! $ids ) {
					break;
				}
				$in = implode( ',', $ids );

				++$result['batches'];
				$wpdb->query( 'START TRANSACTION' );
				try {
					// Meta + term relationships first, then the revision posts themselves.
					Database::execute_or_fail( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ({$in})" );
					Database::execute_or_fail( "DELETE FROM {$wpdb->term_relationships} WHERE object_id IN ({$in})" );
					$deleted = Database::execute_or_fail( "DELETE FROM {$wpdb->posts} WHERE ID IN ({$in})" );
					$wpdb->query( 'COMMIT' );
				} catch ( DatabaseQueryException $e ) {
					$wpdb->query( 'ROLLBACK' );
					$result['stopped'] = true;
					$result['error']   = $e->getMessage();
					break;
				}
				$result['deleted'] += $deleted;
				foreach ( $ids as $pid ) {
					clean_post_cache( $pid );
				}
			}
		} finally {
			Database::release_lock();
		}

		$result['remaining'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE {$where}" );
		return $result;
	}
}
