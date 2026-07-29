<?php
/**
 * Audit logger: structured per-run record. Stores metadata IDs and hashes, never full
 * Elementor JSON. Kept as a bounded option-backed ring buffer (last 50 runs).
 *
 * @package elementor-meta-deduper
 */

namespace EMDD;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Audit {

	private const OPTION = 'emdd_audit_log';
	private const MAX    = 50;

	/**
	 * Record a run. $data is merged with environment defaults.
	 */
	public static function record( array $data ): void {
		$server = Database::server();
		$entry  = array_merge(
			array(
				'uuid'          => $data['run_id'] ?? ( function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( '', true ) ),
				'started'       => $data['started'] ?? current_time( 'mysql' ),
				'ended'         => current_time( 'mysql' ),
				'actor'         => self::actor(),
				'environment'   => wp_get_environment_type(),
				'db_flavor'     => $server['flavor'],
				'db_version'    => $server['version'],
				'plugin_version'=> defined( 'EMDD_VERSION' ) ? EMDD_VERSION : '',
				'elementor'     => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : get_option( 'elementor_version', '' ),
				'retention'     => $data['retention'] ?? get_option( 'emdd_keep_mode', 'newest' ),
				'target_keys'   => Database::KEYS,
				'planned'       => $data['planned'] ?? null,
				'completed'     => $data['completed'] ?? null,
				'failed_batch'  => $data['failed_batch'] ?? null,
				'sql_error'     => $data['sql_error'] ?? null,
				'export_path'   => $data['export_path'] ?? null,
				'verification'  => $data['verification'] ?? null,
				'optimization'  => $data['optimization'] ?? null,
			),
			array()
		);

		$log = get_option( self::OPTION, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		array_unshift( $log, $entry );
		$log = array_slice( $log, 0, self::MAX );
		update_option( self::OPTION, $log, false );
	}

	public static function all(): array {
		$log = get_option( self::OPTION, array() );
		return is_array( $log ) ? $log : array();
	}

	public static function latest(): ?array {
		$log = self::all();
		return $log[0] ?? null;
	}

	private static function actor(): string {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return 'wp-cli';
		}
		$u = wp_get_current_user();
		return ( $u && $u->ID ) ? ( 'user:' . $u->user_login ) : 'system';
	}
}
