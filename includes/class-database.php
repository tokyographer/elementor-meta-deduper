<?php
/**
 * Database safety layer: fail-loud query execution + server detection.
 *
 * The ONLY approved way to run destructive SQL in this plugin. $wpdb->query() returns
 * false on error and does not throw; execute_or_fail() converts that into an exception
 * so a failed statement inside a transaction triggers ROLLBACK instead of a silent COMMIT.
 *
 * @package elementor-meta-deduper
 */

namespace EMDD;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Thrown when a database statement fails (false return or non-empty last_error). */
final class DatabaseQueryException extends \RuntimeException {}

final class Database {

	/** Supported single-value Elementor meta keys. */
	public const KEYS = array(
		'_elementor_data',
		'_elementor_page_settings',
		'_elementor_edit_mode',
		'_elementor_template_type',
		'_elementor_version',
	);

	/**
	 * Run a statement, or throw. Returns affected/selected row count.
	 *
	 * @throws DatabaseQueryException
	 */
	public static function execute_or_fail( string $sql ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- callers pass fully-built, escaped SQL.
		$result = $wpdb->query( $sql );
		if ( false === $result || '' !== (string) $wpdb->last_error ) {
			throw new DatabaseQueryException(
				sprintf(
					'Database query failed: %s | Query: %s',
					$wpdb->last_error ? $wpdb->last_error : 'unknown error',
					$wpdb->last_query
				)
			);
		}
		return (int) $result;
	}

	/** Keys as an escaped SQL IN() list. Values are constants — safe. */
	public static function keys_in( ?array $keys = null ): string {
		$keys = $keys ?: self::KEYS;
		$keys = array_values( array_intersect( $keys, self::KEYS ) ); // whitelist enforce
		if ( ! $keys ) {
			$keys = self::KEYS;
		}
		return "'" . implode( "','", array_map( 'esc_sql', $keys ) ) . "'";
	}

	/**
	 * Server flavor + version.
	 *
	 * @return array{flavor:string,version:string,is_mariadb:bool}
	 */
	public static function server(): array {
		global $wpdb;
		$version = (string) $wpdb->get_var( 'SELECT VERSION()' );
		$comment = (string) $wpdb->get_var( "SELECT @@version_comment" );
		$is_maria = ( false !== stripos( $version, 'mariadb' ) ) || ( false !== stripos( $comment, 'mariadb' ) );
		return array(
			'flavor'     => $is_maria ? 'MariaDB' : 'MySQL',
			'version'    => $version,
			'is_mariadb' => $is_maria,
		);
	}

	/** Acquire a short-lived run lock. Returns false if another run holds it. */
	public static function acquire_lock( int $ttl = 900 ): bool {
		if ( get_transient( 'emdd_run_lock' ) ) {
			return false;
		}
		set_transient( 'emdd_run_lock', time(), $ttl );
		return true;
	}

	public static function release_lock(): void {
		delete_transient( 'emdd_run_lock' );
	}
}
