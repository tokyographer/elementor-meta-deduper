<?php
/**
 * Analyzer: per-(post, key) duplicate classification + WPML translation awareness.
 * Decides retention safety; never deletes.
 *
 * @package elementor-meta-deduper
 */

namespace EMDD;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Analyzer {

	/**
	 * Classify one post's duplicates for a given key.
	 *
	 * @return array{post_id:int,meta_key:string,kind:string,copies:int,distinct:int,
	 *               keep_id:int,delete_ids:int[],keep_hash:string,json_valid:bool,
	 *               bytes:int,needs_review:bool,reason:string,language:string,translation:bool}
	 */
	public static function classify( int $post_id, string $meta_key, string $keep_mode ): array {
		global $wpdb;
		$meta_key = in_array( $meta_key, Database::KEYS, true ) ? $meta_key : '_elementor_data';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_id, meta_value FROM {$wpdb->postmeta}
				 WHERE post_id=%d AND meta_key=%s ORDER BY meta_id ASC",
				$post_id,
				$meta_key
			),
			ARRAY_A
		) ?: array();

		$copies = count( $rows );
		$hashes = array();
		$sizes  = array();
		foreach ( $rows as $r ) {
			$hashes[ (int) $r['meta_id'] ] = md5( (string) $r['meta_value'] );
			$sizes[ (int) $r['meta_id'] ]  = strlen( (string) $r['meta_value'] );
		}
		$ids       = array_keys( $hashes );
		$distinct  = count( array_unique( $hashes ) );
		$keep_id   = ( 'oldest' === $keep_mode ) ? min( $ids ) : max( $ids );
		$delete_ids = array_values( array_diff( $ids, array( $keep_id ) ) );

		// JSON validity of the row we intend to keep.
		$keep_value = '';
		foreach ( $rows as $r ) {
			if ( (int) $r['meta_id'] === $keep_id ) {
				$keep_value = (string) $r['meta_value'];
				break;
			}
		}
		// Only _elementor_data is JSON; other keys hold plain strings (e.g. "builder", "4.3.4").
		$json_valid = in_array( $meta_key, Database::JSON_KEYS, true )
			? ( '' !== $keep_value && null !== json_decode( $keep_value, true ) )
			: true;

		$kind = ( $copies <= 1 ) ? 'single' : ( 1 === $distinct ? 'identical' : 'divergent' );

		// Retention decision.
		$needs_review = false;
		$reason       = '';
		if ( 'identical' === $kind ) {
			$reason = 'identical copies — lossless dedup';
		} elseif ( 'divergent' === $kind ) {
			$reason = sprintf( 'divergent (%d distinct) — keep %s per policy', $distinct, $keep_mode );
		} elseif ( 'single' === $kind ) {
			$reason = 'no duplicates';
		}
		if ( ! $json_valid && $copies > 1 ) {
			$needs_review = true;
			$reason       = 'kept row is not valid JSON — manual review required';
		}

		$wpml = self::wpml_info( $post_id );

		return array(
			'post_id'      => $post_id,
			'meta_key'     => $meta_key,
			'kind'         => $kind,
			'copies'       => $copies,
			'distinct'     => $distinct,
			'keep_id'      => $keep_id,
			'delete_ids'   => $delete_ids,
			'keep_hash'    => $hashes[ $keep_id ] ?? '',
			'json_valid'   => $json_valid,
			'bytes'        => array_sum( $sizes ),
			'delete_bytes' => array_sum( array_intersect_key( $sizes, array_flip( $delete_ids ) ) ),
			'needs_review' => $needs_review,
			'reason'       => $reason,
			'language'     => $wpml['language'],
			'translation'  => $wpml['translation'],
		);
	}

	/**
	 * WPML classification for a post. Degrades gracefully if WPML tables are absent.
	 *
	 * @return array{language:string,translation:bool,in_wpml:bool}
	 */
	public static function wpml_info( int $post_id ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'icl_translations';
		// Table existence check (cached by MySQL; cheap).
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( ! $exists ) {
			return array( 'language' => '', 'translation' => false, 'in_wpml' => false );
		}
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT language_code, source_language_code FROM {$table}
				 WHERE element_id=%d AND element_type LIKE 'post_%' LIMIT 1",
				$post_id
			),
			ARRAY_A
		);
		if ( ! $row ) {
			return array( 'language' => '', 'translation' => false, 'in_wpml' => false );
		}
		return array(
			'language'    => (string) $row['language_code'],
			'translation' => ! empty( $row['source_language_code'] ),
			'in_wpml'     => true,
		);
	}

	/**
	 * Aggregate classification summary over all affected (post, key) pairs across every
	 * supported key. Kind counters count pairs; WPML counters count distinct posts.
	 *
	 * @return array
	 */
	public static function summary( string $keep_mode ): array {
		$out = array(
			'identical'       => 0,
			'divergent'       => 0,
			'needs_review'    => 0,
			'translations'    => 0,
			'originals'       => 0,
			'not_in_wpml'     => 0,
			'auto_cleanable'  => 0,
			'by_key'          => array(),
		);
		$seen_posts = array();
		foreach ( Database::KEYS as $key ) {
			$ids = Scanner::affected_post_ids( $key );
			$out['by_key'][ $key ] = count( $ids );
			foreach ( $ids as $id ) {
				$c = self::classify( $id, $key, $keep_mode );
				if ( 'identical' === $c['kind'] ) {
					++$out['identical'];
				} elseif ( 'divergent' === $c['kind'] ) {
					++$out['divergent'];
				}
				if ( $c['needs_review'] ) {
					++$out['needs_review'];
				}
				if ( 'identical' === $c['kind'] && ! $c['needs_review'] ) {
					++$out['auto_cleanable'];
				}
				if ( isset( $seen_posts[ $id ] ) ) {
					continue;
				}
				$seen_posts[ $id ] = true;
				if ( $c['translation'] ) {
					++$out['translations'];
				} elseif ( $c['language'] ) {
					++$out['originals'];
				} else {
					++$out['not_in_wpml'];
				}
			}
		}
		$out['affected_posts'] = count( $seen_posts );
		return $out;
	}
}
