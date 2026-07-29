<?php
/**
 * Planner: builds a deterministic deletion PLAN (manifest) honoring retention policy
 * and the max-delete cap. Never deletes. The plan is what the executor consumes and
 * what the export service records before anything is removed.
 *
 * @package elementor-meta-deduper
 */

namespace EMDD;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Planner {

	/**
	 * @param array $opts {
	 *   keep:string 'newest'|'oldest',
	 *   max_delete:int,
	 *   include_divergent:bool,   // clean divergent under explicit policy
	 *   post_id:?int,             // restrict to one post
	 *   translations_only:bool,
	 * }
	 * @return array{run_id:string,created:string,keep:string,items:array,
	 *               totals:array,capped:bool,skipped_review:int}
	 */
	public static function build( array $opts ): array {
		$keep   = ( 'oldest' === ( $opts['keep'] ?? 'newest' ) ) ? 'oldest' : 'newest';
		$cap    = max( 1, (int) ( $opts['max_delete'] ?? 3000 ) );
		$incl_d = ! empty( $opts['include_divergent'] );
		$only_t = ! empty( $opts['translations_only'] );
		$one    = isset( $opts['post_id'] ) ? (int) $opts['post_id'] : 0;

		$ids = $one ? array( $one ) : Scanner::affected_post_ids( '_elementor_data' );

		$items          = array();
		$planned_del    = 0;
		$planned_bytes  = 0;
		$skipped_review = 0;
		$capped         = false;

		foreach ( $ids as $pid ) {
			$c = Analyzer::classify( $pid, '_elementor_data', $keep );
			if ( 'single' === $c['kind'] ) {
				continue;
			}
			if ( $only_t && ! $c['translation'] ) {
				continue;
			}
			// Retention policy.
			if ( $c['needs_review'] ) {
				++$skipped_review;
				continue;
			}
			if ( 'divergent' === $c['kind'] && ! $incl_d ) {
				++$skipped_review;
				continue;
			}
			// Cap check (whole-post atomic: don't half-delete a post).
			if ( $planned_del + count( $c['delete_ids'] ) > $cap ) {
				$capped = true;
				break;
			}
			$items[]        = array(
				'post_id'      => $c['post_id'],
				'meta_key'     => $c['meta_key'],
				'kind'         => $c['kind'],
				'copies'       => $c['copies'],
				'keep_id'      => $c['keep_id'],
				'delete_ids'   => $c['delete_ids'],
				'keep_hash'    => $c['keep_hash'],
				'delete_bytes' => $c['delete_bytes'],
				'language'     => $c['language'],
				'translation'  => $c['translation'],
				'reason'       => $c['reason'],
			);
			$planned_del   += count( $c['delete_ids'] );
			$planned_bytes += $c['delete_bytes'];
		}

		return array(
			'run_id'         => self::run_id(),
			'created'        => current_time( 'mysql' ),
			'keep'           => $keep,
			'items'          => $items,
			'skipped_review' => $skipped_review,
			'capped'         => $capped,
			'totals'         => array(
				'posts'         => count( $items ),
				'delete_rows'   => $planned_del,
				'delete_bytes'  => $planned_bytes,
				'cap'           => $cap,
			),
		);
	}

	private static function run_id(): string {
		// wp_generate_uuid4() avoids Math.random restrictions; available since WP 4.7.
		return function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'emdd_', true );
	}
}
