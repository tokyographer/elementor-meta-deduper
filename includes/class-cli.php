<?php
/**
 * WP-CLI controller. Large cleanups belong here, not in a browser request.
 *
 * Commands: scan | plan | export | clean | verify | optimize
 * Exit codes (see HARDENING_PLAN §Exit codes):
 *   0 success/no-op · 1 usage · 2 failed+rolled back · 3 partial/capped · 4 residue · 5 needs review
 *
 * @package elementor-meta-deduper
 */

namespace EMDD;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CLI {

	/**
	 * Read-only scan with accurate totals.
	 *
	 * ## OPTIONS
	 * [--format=<format>] : table|json. default: table
	 */
	public function scan( $args, $assoc ) {
		$t = Scanner::totals();
		$s = Analyzer::summary( $t['keep_mode'] );
		$out = array(
			'postmeta_mb'    => $t['postmeta_mb'],
			'redundant_rows' => $t['redundant_rows'],
			'redundant_mb'   => round( $t['redundant_bytes'] / 1048576, 1 ),
			'affected_posts' => $t['affected_posts'],
			'identical'      => $s['identical'],
			'divergent'      => $s['divergent'],
			'needs_review'   => $s['needs_review'],
			'translations'   => $s['translations'],
			'originals'      => $s['originals'],
			'not_in_wpml'    => $s['not_in_wpml'],
		);
		$this->emit( $out, $assoc );
		$this->halt( $t['redundant_rows'] > 0 ? 0 : 0 );
	}

	/**
	 * Build (and print) a deletion plan without deleting.
	 *
	 * ## OPTIONS
	 * [--keep=<mode>] : newest|oldest. default: option
	 * [--max-delete=<n>] : default 3000
	 * [--post-id=<id>] :
	 * [--translations-only] :
	 * [--include-divergent] : clean divergent under explicit policy
	 * [--format=<format>] : table|json
	 */
	public function plan( $args, $assoc ) {
		$plan = Planner::build( $this->plan_opts( $assoc ) );
		$this->print_plan( $plan, $assoc );
		if ( $plan['skipped_review'] > 0 && empty( $plan['items'] ) ) {
			$this->halt( 5 );
		}
		$this->halt( 0 );
	}

	/**
	 * Write a manifest JSON for the current plan.
	 *
	 * ## OPTIONS
	 * [--export=<path>] : output file. default: uploads/emdd-manifests/
	 * [--keep=<mode>] :
	 * [--max-delete=<n>] :
	 * [--include-divergent] :
	 * [--translations-only] :
	 */
	public function export( $args, $assoc ) {
		$plan = Planner::build( $this->plan_opts( $assoc ) );
		$path = Export::write( $plan, $assoc['export'] ?? null );
		\WP_CLI::success( 'Manifest written: ' . $path );
		$this->halt( 0 );
	}

	/**
	 * Execute the cleanup (batched, transactional, verified).
	 *
	 * ## OPTIONS
	 * [--keep=<mode>] : newest|oldest
	 * [--batch-size=<n>] : default 25
	 * [--max-delete=<n>] : default 3000
	 * [--post-id=<id>] :
	 * [--translations-only] :
	 * [--include-divergent] :
	 * [--dry-run] : plan + manifest only, no deletion
	 * [--resume] : resume the last run
	 * [--export=<path>] : manifest path
	 * [--yes] : skip confirmation
	 */
	public function clean( $args, $assoc ) {
		$started = current_time( 'mysql' );
		$plan    = Planner::build( $this->plan_opts( $assoc ) );

		if ( empty( $plan['items'] ) ) {
			if ( $plan['skipped_review'] > 0 ) {
				\WP_CLI::warning( sprintf( '%d post(s) need manual review (divergent/invalid). Use --include-divergent to clean divergent under policy.', $plan['skipped_review'] ) );
				$this->halt( 5 );
			}
			\WP_CLI::success( 'Nothing to clean.' );
			$this->halt( 0 );
		}

		$path = Export::write( $plan, $assoc['export'] ?? null );
		\WP_CLI::log( 'Manifest: ' . $path );

		if ( isset( $assoc['dry-run'] ) ) {
			$this->print_plan( $plan, $assoc );
			\WP_CLI::success( 'Dry run complete — no rows deleted.' );
			$this->halt( 0 );
		}

		if ( ! isset( $assoc['yes'] ) ) {
			\WP_CLI::confirm( sprintf( 'Delete %d rows across %d posts? (backup first)', $plan['totals']['delete_rows'], $plan['totals']['posts'] ) );
		}

		$res = Executor::run( $plan, array(
			'batch_size' => (int) ( $assoc['batch-size'] ?? 25 ),
			'resume'     => isset( $assoc['resume'] ),
		) );

		$post_ids = array_map( static fn( $i ) => (int) $i['post_id'], $plan['items'] );
		$verify   = $res['stopped'] ? null : Verifier::verify( $post_ids );

		Audit::record( array(
			'run_id'       => $plan['run_id'],
			'started'      => $started,
			'retention'    => $plan['keep'],
			'planned'      => $plan['totals'],
			'completed'    => array( 'deleted_rows' => $res['deleted_rows'], 'posts_done' => $res['posts_done'] ),
			'failed_batch' => $res['failed_batch'],
			'sql_error'    => $res['error'],
			'export_path'  => $path,
			'verification' => $verify,
		) );

		if ( $res['stopped'] && $res['error'] ) {
			\WP_CLI::warning( 'Run stopped and rolled back: ' . $res['error'] );
			$this->halt( 2 );
		}
		\WP_CLI::success( sprintf( 'Deleted %d rows across %d posts.', $res['deleted_rows'], $res['posts_done'] ) );
		foreach ( Verifier::followups() as $f ) {
			\WP_CLI::log( '  → ' . $f );
		}
		if ( $plan['capped'] ) {
			\WP_CLI::warning( 'max-delete cap reached — rerun with --resume for the remainder.' );
			$this->halt( 3 );
		}
		if ( $verify && ! $verify['clean'] ) {
			\WP_CLI::warning( sprintf( '%d residual duplicate rows remain.', $verify['residual_rows'] ) );
			$this->halt( 4 );
		}
		Executor::clear_progress( $plan['run_id'] );
		$this->halt( 0 );
	}

	/** Re-scan and report residue. */
	public function verify( $args, $assoc ) {
		$v = Verifier::verify();
		$this->emit( $v, $assoc );
		$this->halt( $v['clean'] ? 0 : 4 );
	}

	/** Evidence-based table optimize. Never run from cron. */
	public function optimize( $args, $assoc ) {
		$r = Optimizer::run();
		\WP_CLI::log( sprintf( '%s %s | reclaimed ~%.1f MB | workaround: %s',
			$r['server']['flavor'], $r['server']['version'], $r['reclaimed_mb'], $r['workaround_used'] ? 'yes' : 'no' ) );
		foreach ( $r['warnings'] as $w ) {
			\WP_CLI::warning( $w );
		}
		foreach ( $r['errors'] as $e ) {
			\WP_CLI::warning( $e );
		}
		Audit::record( array( 'optimization' => $r ) );
		$this->halt( $r['errors'] ? 2 : 0 );
	}

	// ── helpers ──
	private function plan_opts( array $assoc ): array {
		return array(
			'keep'              => $assoc['keep'] ?? get_option( 'emdd_keep_mode', 'newest' ),
			'max_delete'        => (int) ( $assoc['max-delete'] ?? 3000 ),
			'post_id'           => isset( $assoc['post-id'] ) ? (int) $assoc['post-id'] : 0,
			'translations_only' => isset( $assoc['translations-only'] ),
			'include_divergent' => isset( $assoc['include-divergent'] ),
		);
	}

	private function print_plan( array $plan, array $assoc ): void {
		\WP_CLI::log( sprintf( 'Plan %s | keep=%s | posts=%d delete_rows=%d (%.1f MB) skipped_review=%d capped=%s',
			$plan['run_id'], $plan['keep'], $plan['totals']['posts'], $plan['totals']['delete_rows'],
			$plan['totals']['delete_bytes'] / 1048576, $plan['skipped_review'], $plan['capped'] ? 'yes' : 'no' ) );
		if ( 'json' === ( $assoc['format'] ?? 'table' ) ) {
			\WP_CLI::log( wp_json_encode( $plan['items'] ) );
		}
	}

	private function emit( array $data, array $assoc ): void {
		if ( 'json' === ( $assoc['format'] ?? 'table' ) ) {
			\WP_CLI::log( wp_json_encode( $data ) );
			return;
		}
		foreach ( $data as $k => $v ) {
			\WP_CLI::log( str_pad( $k, 16 ) . ': ' . ( is_scalar( $v ) ? $v : wp_json_encode( $v ) ) );
		}
	}

	private function halt( int $code ): void {
		if ( 0 === $code ) {
			\WP_CLI::halt( 0 );
		}
		\WP_CLI::halt( $code );
	}
}
