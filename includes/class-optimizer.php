<?php
/**
 * Optimizer: environment-aware table rebuild with EVIDENCE of reclaimed space.
 * Kept fully separate from cleanup and NEVER runs from cron. Only relaxes sql_mode
 * when the MySQL strict-mode zero-date error on wp_posts actually occurs.
 *
 * @package elementor-meta-deduper
 */

namespace EMDD;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optimizer {

	/**
	 * @return array{server:array,before:array,after:array,reclaimed_mb:float,
	 *               warnings:string[],errors:string[],workaround_used:bool}
	 */
	public static function run(): array {
		global $wpdb;
		$server   = Database::server();
		$warnings = array();
		$errors   = array();
		$workaround_used = false;

		$tables = array( $wpdb->postmeta, $wpdb->options, $wpdb->term_relationships, $wpdb->posts );
		$before = self::sizes( $tables );

		// postmeta/options/term_relationships: straightforward.
		foreach ( array( $wpdb->postmeta, $wpdb->options, $wpdb->term_relationships ) as $t ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$r = $wpdb->query( "OPTIMIZE TABLE {$t}" );
			if ( false === $r ) {
				$errors[] = sprintf( 'OPTIMIZE %s failed: %s', $t, $wpdb->last_error );
			}
		}

		// wp_posts: on MySQL strict mode the rebuild hits "Invalid default value for 'post_date'".
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$posts_result = $wpdb->query( "OPTIMIZE TABLE {$wpdb->posts}" );
		$needs_workaround = ( false === $posts_result )
			|| ( '' !== (string) $wpdb->last_error && false !== stripos( $wpdb->last_error, 'post_date' ) );

		if ( $needs_workaround && ! $server['is_mariadb'] ) {
			$prev_mode = (string) $wpdb->get_var( 'SELECT @@SESSION.sql_mode' );
			$wpdb->query( "SET SESSION sql_mode=''" );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$r = $wpdb->query( "ALTER TABLE {$wpdb->posts} ENGINE=InnoDB" );
			$wpdb->query( $wpdb->prepare( 'SET SESSION sql_mode=%s', $prev_mode ) );
			if ( false === $r ) {
				$errors[] = 'wp_posts rebuild failed even with relaxed sql_mode: ' . $wpdb->last_error;
			} else {
				$workaround_used = true;
			}
		} elseif ( $needs_workaround && $server['is_mariadb'] ) {
			$warnings[] = 'wp_posts OPTIMIZE reported an issue on MariaDB; sql_mode workaround not applied (MySQL-specific).';
		}

		$wpdb->query( "ANALYZE TABLE {$wpdb->postmeta}, {$wpdb->posts}" );
		$after = self::sizes( $tables );

		$reclaimed = 0.0;
		foreach ( $before as $t => $mb ) {
			$reclaimed += max( 0, $mb - ( $after[ $t ] ?? $mb ) );
		}
		if ( $reclaimed <= 0 ) {
			$warnings[] = 'No measurable space reclaimed (information_schema is cached; check real .ibd sizes for ground truth).';
		}

		return array(
			'server'          => $server,
			'before'          => $before,
			'after'           => $after,
			'reclaimed_mb'    => round( $reclaimed, 1 ),
			'warnings'        => $warnings,
			'errors'          => $errors,
			'workaround_used' => $workaround_used,
		);
	}

	private static function sizes( array $tables ): array {
		global $wpdb;
		$out = array();
		foreach ( $tables as $t ) {
			$out[ $t ] = (float) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT ROUND((data_length+index_length)/1048576,1) FROM information_schema.tables
					 WHERE table_schema=DATABASE() AND table_name=%s",
					$t
				)
			);
		}
		return $out;
	}
}
