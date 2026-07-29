<?php
/**
 * Executor: batched, transactional deletion via Database::execute_or_fail().
 * Each batch is its own transaction; any failed statement throws → that batch rolls back
 * and the run stops (fail-closed). Progress is recorded for --resume. Idempotent: a row
 * already gone is simply not re-deleted.
 *
 * @package elementor-meta-deduper
 */

namespace EMDD;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Executor {

	/**
	 * @param array $plan   Plan from Planner::build().
	 * @param array $opts   { batch_size:int, resume:bool }
	 * @return array{run_id:string,deleted_rows:int,deleted_bytes:int,posts_done:int,
	 *               batches:int,failed_batch:?int,error:?string,stopped:bool}
	 */
	public static function run( array $plan, array $opts = array() ): array {
		$batch_size = max( 1, (int) ( $opts['batch_size'] ?? 25 ) );
		$items      = $plan['items'] ?? array();
		$run_id     = $plan['run_id'];

		$resuming = ! empty( $opts['resume'] );
		$progress = get_option( 'emdd_progress_' . $run_id, array() );
		$start    = $resuming ? (int) ( $progress['posts_done'] ?? 0 ) : 0;

		$result = array(
			'run_id'        => $run_id,
			// Counters seed from prior progress ONLY when resuming; a fresh run starts at 0.
			'deleted_rows'  => $resuming ? (int) ( $progress['deleted_rows'] ?? 0 ) : 0,
			'deleted_bytes' => $resuming ? (int) ( $progress['deleted_bytes'] ?? 0 ) : 0,
			'posts_done'    => $start,
			'batches'       => 0,
			'failed_batch'  => null,
			'error'         => null,
			'stopped'       => false,
		);

		if ( ! Database::acquire_lock() ) {
			$result['error']   = 'Another cleanup run holds the lock; aborting.';
			$result['stopped'] = true;
			return $result;
		}

		global $wpdb;
		try {
			$batches = array_chunk( array_slice( $items, $start ), $batch_size );
			foreach ( $batches as $bi => $batch ) {
				++$result['batches'];
				// Per-batch tentative counters; folded into result ONLY after COMMIT succeeds.
				$b_rows  = 0;
				$b_bytes = 0;
				$b_posts = array();
				$wpdb->query( 'START TRANSACTION' );
				try {
					foreach ( $batch as $item ) {
						$del_ids = array_map( 'intval', $item['delete_ids'] );
						if ( ! $del_ids ) {
							continue;
						}
						$in  = implode( ',', $del_ids );
						// Bounded to specific meta_ids of a single post+key — safe, idempotent.
						$sql = "DELETE FROM {$wpdb->postmeta}
						        WHERE post_id=" . (int) $item['post_id'] . "
						        AND meta_key='" . esc_sql( $item['meta_key'] ) . "'
						        AND meta_id IN ({$in})";
						$affected  = Database::execute_or_fail( $sql );
						$b_rows   += $affected;
						$b_bytes  += (int) $item['delete_bytes'];
						$b_posts[] = (int) $item['post_id'];
					}
					$wpdb->query( 'COMMIT' );
				} catch ( DatabaseQueryException $e ) {
					$wpdb->query( 'ROLLBACK' );
					$result['failed_batch'] = $bi;
					$result['error']        = $e->getMessage();
					$result['stopped']      = true;
					break; // discard tentative counters — nothing was committed.
				}
				// Batch committed: fold in real counts and invalidate caches for touched posts.
				$result['deleted_rows']  += $b_rows;
				$result['deleted_bytes'] += $b_bytes;
				$result['posts_done']    += count( $b_posts );
				foreach ( $b_posts as $pid ) {
					clean_post_cache( $pid );
				}
				// Persist progress after each committed batch (resume point).
				update_option(
					'emdd_progress_' . $run_id,
					array(
						'posts_done'    => $result['posts_done'],
						'deleted_rows'  => $result['deleted_rows'],
						'deleted_bytes' => $result['deleted_bytes'],
					),
					false
				);
			}
		} finally {
			Database::release_lock();
		}

		return $result;
	}

	/** Clear stored progress for a completed/abandoned run. */
	public static function clear_progress( string $run_id ): void {
		delete_option( 'emdd_progress_' . $run_id );
	}
}
