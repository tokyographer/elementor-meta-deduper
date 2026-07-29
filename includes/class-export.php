<?php
/**
 * Export service: writes/loads the pre-delete manifest (JSON). This is the record of
 * exactly what will be / was removed — the lightweight undo reference (row IDs + hashes,
 * not full JSON payloads).
 *
 * @package elementor-meta-deduper
 */

namespace EMDD;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Export {

	/** Default export directory inside uploads (created on demand). */
	public static function dir(): string {
		$up  = wp_upload_dir();
		$dir = trailingslashit( $up['basedir'] ) . 'emdd-manifests';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			// Prevent listing/serving.
			@file_put_contents( $dir . '/index.php', "<?php // Silence is golden.\n" );
			@file_put_contents( $dir . '/.htaccess', "Deny from all\n" );
		}
		return $dir;
	}

	/**
	 * Write a manifest for a plan. Returns the absolute path.
	 *
	 * @param array       $plan
	 * @param string|null $path Optional explicit path.
	 */
	public static function write( array $plan, ?string $path = null ): string {
		$path = $path ?: ( self::dir() . '/manifest-' . $plan['run_id'] . '.json' );
		$doc  = array(
			'run_id'  => $plan['run_id'],
			'created' => $plan['created'],
			'keep'    => $plan['keep'],
			'totals'  => $plan['totals'],
			'skipped_review' => $plan['skipped_review'] ?? 0,
			'capped'  => $plan['capped'] ?? false,
			'items'   => $plan['items'],
			'site'    => home_url(),
			'db'      => Database::server(),
			'generated_by' => 'elementor-meta-deduper ' . ( defined( 'EMDD_VERSION' ) ? EMDD_VERSION : '' ),
		);
		$json = wp_json_encode( $doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( false === file_put_contents( $path, $json ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			throw new \RuntimeException( 'Failed to write manifest to ' . $path );
		}
		return $path;
	}

	/** Load a manifest back into an array. */
	public static function load( string $path ): array {
		if ( ! is_readable( $path ) ) {
			throw new \RuntimeException( 'Manifest not readable: ' . $path );
		}
		$data = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! is_array( $data ) ) {
			throw new \RuntimeException( 'Manifest is not valid JSON: ' . $path );
		}
		return $data;
	}
}
