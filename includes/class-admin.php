<?php
/**
 * Admin controller. Scans/reports/warns; permits only SMALL targeted cleanups and
 * refuses large browser-based runs, directing the operator to WP-CLI (fixes H2).
 *
 * @package elementor-meta-deduper
 */

namespace EMDD;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Admin {

	/**
	 * Max rows a single browser request will delete in one click. The job is processed in
	 * resumable chunks: each click cleans up to this many rows (batched + transactional
	 * internally) and reports how many remain, so large sites finish over a few clicks
	 * without ever running an unbounded request. WP-CLI is still recommended for very large
	 * or scripted runs, but the browser path now actually makes progress on its own.
	 */
	public const PER_REQUEST_MAX_ROWS = 3000;

	public static function menu(): void {
		add_management_page(
			__( 'Elementor Meta Deduper', 'elementor-meta-deduper' ),
			__( 'Elementor Meta Deduper', 'elementor-meta-deduper' ),
			'manage_options',
			'elementor-meta-deduper',
			array( __CLASS__, 'render' )
		);
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'elementor-meta-deduper' ) );
		}
		$notice = '';
		$notice_type = 'info';

		// Settings.
		if ( isset( $_POST['emdd_action'] ) && 'save_settings' === $_POST['emdd_action'] ) {
			check_admin_referer( 'emdd_settings' );
			update_option( 'emdd_keep_mode', ( ( $_POST['keep'] ?? '' ) === 'oldest' ) ? 'oldest' : 'newest' );
			update_option( 'emdd_alert_email', sanitize_email( wp_unslash( $_POST['alert_email'] ?? '' ) ) );
			update_option( 'emdd_alert_threshold', max( 1, (int) ( $_POST['alert_threshold'] ?? 1 ) ) );
			$notice = __( 'Settings saved.', 'elementor-meta-deduper' );
		}

		// Cleanup — processed in resumable chunks; each click cleans up to PER_REQUEST_MAX_ROWS.
		if ( isset( $_POST['emdd_action'] ) && 'cleanup' === $_POST['emdd_action'] ) {
			check_admin_referer( 'emdd_cleanup' );
			if ( empty( $_POST['backup_confirmed'] ) ) {
				$notice = __( 'Please confirm you have a backup before running a cleanup.', 'elementor-meta-deduper' );
				$notice_type = 'error';
			} else {
				$keep = get_option( 'emdd_keep_mode', 'newest' );
				$plan = Planner::build( array(
					'keep'              => $keep,
					'max_delete'        => self::PER_REQUEST_MAX_ROWS,
					'post_id'           => (int) ( $_POST['post_id'] ?? 0 ),
					'include_divergent' => ! empty( $_POST['include_divergent'] ),
				) );

				if ( empty( $plan['items'] ) ) {
					$notice = $plan['skipped_review'] > 0
						? sprintf( __( '%d post(s) are divergent or need review — tick "Also clean divergent posts" to include them, or review manually.', 'elementor-meta-deduper' ), $plan['skipped_review'] )
						: __( 'Nothing to clean.', 'elementor-meta-deduper' );
					$notice_type = 'info';
				} else {
					$path = Export::write( $plan );
					$res  = Executor::run( $plan, array( 'batch_size' => 25 ) );
					$ids  = array_map( static fn( $i ) => (int) $i['post_id'], $plan['items'] );
					$verify = $res['stopped'] ? null : Verifier::verify( $ids );
					Audit::record( array(
						'run_id'      => $plan['run_id'],
						'retention'   => $plan['keep'],
						'planned'     => $plan['totals'],
						'completed'   => array( 'deleted_rows' => $res['deleted_rows'], 'posts_done' => $res['posts_done'] ),
						'sql_error'   => $res['error'],
						'export_path' => $path,
						'verification' => $verify,
					) );
					if ( $res['stopped'] ) {
						$notice = __( 'Cleanup failed and was rolled back: ', 'elementor-meta-deduper' ) . esc_html( (string) $res['error'] );
						$notice_type = 'error';
					} else {
						// Recompute remaining work so the user knows whether to click again.
						$after     = Scanner::totals();
						$remaining = (int) $after['redundant_rows'];
						$notice    = sprintf(
							/* translators: 1: rows deleted, 2: posts, 3: rows remaining */
							__( 'Deleted %1$d rows across %2$d posts. %3$d redundant rows remain.', 'elementor-meta-deduper' ),
							$res['deleted_rows'], $res['posts_done'], $remaining
						);
						if ( $remaining > 0 ) {
							$notice     .= ' ' . __( 'Click “Run cleanup” again to continue.', 'elementor-meta-deduper' );
							$notice_type = 'warning';
						} else {
							$notice     .= ' ' . __( 'All duplicates cleared — now regenerate Elementor CSS and clear caches.', 'elementor-meta-deduper' );
							$notice_type = 'success';
						}
					}
				}
			}
		}

		$totals = Scanner::totals();
		$sample = Scanner::sample( 50 );
		self::view( $totals, $sample, $notice, $notice_type );
	}

	private static function view( array $totals, array $sample, string $notice, string $notice_type ): void {
		$keep = get_option( 'emdd_keep_mode', 'newest' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Elementor Meta Deduper', 'elementor-meta-deduper' ); ?></h1>
			<?php if ( $notice ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice_type ); ?>"><p><?php echo esc_html( $notice ); ?></p></div>
			<?php endif; ?>

			<?php if ( $totals['redundant_rows'] > 0 ) : ?>
				<div class="notice notice-warning"><p>
					<strong><?php echo esc_html( sprintf( __( '%1$d redundant rows across %2$d posts (%3$.1f MB).', 'elementor-meta-deduper' ), $totals['redundant_rows'], $totals['affected_posts'], $totals['redundant_bytes'] / 1048576 ) ); ?></strong>
					<?php echo esc_html( sprintf( __( 'Cleanup runs in chunks of up to %d rows per click. Update SitePress + WPML Page Builders + Elementor to stop regrowth.', 'elementor-meta-deduper' ), self::PER_REQUEST_MAX_ROWS ) ); ?>
				</p></div>
			<?php else : ?>
				<div class="notice notice-success"><p><?php esc_html_e( 'No duplicate Elementor meta found.', 'elementor-meta-deduper' ); ?></p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Accurate totals', 'elementor-meta-deduper' ); ?></h2>
			<table class="widefat striped" style="max-width:640px"><tbody>
				<tr><td>wp_postmeta size</td><td><strong><?php echo esc_html( $totals['postmeta_mb'] ); ?> MB</strong></td></tr>
				<tr><td>Redundant rows (full count)</td><td><strong><?php echo (int) $totals['redundant_rows']; ?></strong></td></tr>
				<tr><td>Redundant size</td><td><?php echo esc_html( round( $totals['redundant_bytes'] / 1048576, 1 ) ); ?> MB</td></tr>
				<tr><td>Affected posts (full count)</td><td><?php echo (int) $totals['affected_posts']; ?></td></tr>
			</tbody></table>

			<p class="description"><?php esc_html_e( 'Recommended for anything non-trivial:', 'elementor-meta-deduper' ); ?>
				<code>wp elementor-dedup scan</code> · <code>wp elementor-dedup plan</code> ·
				<code>wp elementor-dedup clean --dry-run</code> · <code>wp elementor-dedup clean</code></p>

			<?php if ( $sample ) : ?>
				<h3><?php esc_html_e( 'Worst pages (display sample)', 'elementor-meta-deduper' ); ?></h3>
				<table class="widefat striped" style="max-width:720px">
					<thead><tr><th>Post</th><th>Copies</th><th>Distinct</th><th>KB</th><th></th></tr></thead>
					<tbody>
					<?php foreach ( $sample as $r ) : ?>
						<tr>
							<td><?php echo (int) $r['post_id']; ?> — <?php echo esc_html( get_the_title( (int) $r['post_id'] ) ); ?>
								<?php echo ( (int) $r['distinct_vals'] > 1 ) ? '<span style="color:#b32d2e"> (divergent)</span>' : ''; ?></td>
							<td><?php echo (int) $r['copies']; ?></td>
							<td><?php echo (int) $r['distinct_vals']; ?></td>
							<td><?php echo (int) $r['kb']; ?></td>
							<td><a href="<?php echo esc_url( (string) get_edit_post_link( (int) $r['post_id'] ) ); ?>"><?php esc_html_e( 'Edit', 'elementor-meta-deduper' ); ?></a></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<hr>
			<h2><?php esc_html_e( 'Run cleanup', 'elementor-meta-deduper' ); ?></h2>
			<p><?php echo esc_html( sprintf( __( 'Each click cleans up to %d rows (batched and transactional). If rows remain, just click again until it reports zero. Identical duplicates are cleaned automatically; tick the box below to also clean divergent posts.', 'elementor-meta-deduper' ), self::PER_REQUEST_MAX_ROWS ) ); ?></p>
			<form method="post" onsubmit="return confirm('<?php echo esc_js( __( 'Run cleanup now?', 'elementor-meta-deduper' ) ); ?>');">
				<?php wp_nonce_field( 'emdd_cleanup' ); ?>
				<input type="hidden" name="emdd_action" value="cleanup">
				<p><label><?php esc_html_e( 'Restrict to post ID (optional, 0 = all):', 'elementor-meta-deduper' ); ?> <input type="number" name="post_id" value="0"></label></p>
				<p><label><input type="checkbox" name="include_divergent"> <?php
					/* translators: %s: newest|oldest */
					echo esc_html( sprintf( __( 'Also clean divergent posts (keeps the %s row)', 'elementor-meta-deduper' ), $keep ) ); ?></label></p>
				<p><label><input type="checkbox" name="backup_confirmed"> <strong><?php esc_html_e( 'I have taken a database backup.', 'elementor-meta-deduper' ); ?></strong></label></p>
				<?php submit_button( __( 'Run cleanup', 'elementor-meta-deduper' ), 'primary' ); ?>
			</form>

			<hr>
			<h2><?php esc_html_e( 'Settings', 'elementor-meta-deduper' ); ?></h2>
			<form method="post">
				<?php wp_nonce_field( 'emdd_settings' ); ?>
				<input type="hidden" name="emdd_action" value="save_settings">
				<table class="form-table">
					<tr><th><?php esc_html_e( 'Row to keep', 'elementor-meta-deduper' ); ?></th><td>
						<label><input type="radio" name="keep" value="newest" <?php checked( $keep, 'newest' ); ?>> <?php esc_html_e( 'Newest (latest synced layout — verify divergent pages)', 'elementor-meta-deduper' ); ?></label><br>
						<label><input type="radio" name="keep" value="oldest" <?php checked( $keep, 'oldest' ); ?>> <?php esc_html_e( 'Oldest (the value WordPress currently serves)', 'elementor-meta-deduper' ); ?></label>
						<p class="description"><?php esc_html_e( 'Neither guarantees zero visual change on divergent posts. Identical-copy posts are always lossless.', 'elementor-meta-deduper' ); ?></p>
					</td></tr>
					<tr><th><?php esc_html_e( 'Monitor email', 'elementor-meta-deduper' ); ?></th><td><input type="email" class="regular-text" name="alert_email" value="<?php echo esc_attr( (string) get_option( 'emdd_alert_email', get_option( 'admin_email' ) ) ); ?>"></td></tr>
					<tr><th><?php esc_html_e( 'Alert threshold (rows)', 'elementor-meta-deduper' ); ?></th><td><input type="number" name="alert_threshold" value="<?php echo (int) get_option( 'emdd_alert_threshold', 1 ); ?>" min="1"></td></tr>
				</table>
				<?php submit_button( __( 'Save settings', 'elementor-meta-deduper' ), 'secondary' ); ?>
			</form>

			<p style="margin-top:2em;color:#666"><em><?php esc_html_e( 'This plugin cleans accumulated data; it does not fix the root cause. Update SitePress + the WPML Page Builders glue module + Elementor to stop regrowth.', 'elementor-meta-deduper' ); ?></em></p>
		</div>
		<?php
	}
}
