<?php
/**
 * Tests the Revisions service against a bloated fixture: scan reports counts, clean
 * deletes revisions + their meta in batches, is idempotent, and respects the cap.
 */
require __DIR__ . '/harness.php';

use EMDD\Revisions;

$wpdb = $GLOBALS['wpdb'];

$scan = Revisions::scan();
echo "Revisions: {$scan['count']}, elementor rows: {$scan['elementor_rows']} ({$scan['elementor_mb']} MB)\n";
ok( $scan['count'] > 0, 'fixture has revisions' );

$posts_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM wp_posts" );
$meta_before  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM wp_postmeta pm JOIN wp_posts p ON p.ID=pm.post_id WHERE p.post_type='revision'" );

echo "\n[T-cap] cap stops early and reports remaining\n";
$capped = Revisions::clean( array( 'batch_size' => 50, 'max_delete' => 100 ) );
ok( ! $capped['stopped'], 'capped run did not error' );
ok( $capped['deleted'] <= 100, 'respected max_delete cap (' . $capped['deleted'] . ')' );
ok( $capped['remaining'] > 0, 'revisions remain after capped run' );

echo "\n[T-full] repeat until zero (resumable-by-repeat)\n";
$loops = 0; $total = $capped['deleted'];
do {
	$r = Revisions::clean( array( 'batch_size' => 200, 'max_delete' => 5000 ) );
	ok( ! $r['stopped'], 'batch completed without error' );
	$total += $r['deleted'];
	if ( ++$loops > 20 ) { ok( false, 'not converging' ); break; }
} while ( $r['remaining'] > 0 );

$after = Revisions::scan();
eq( $after['count'], 0, 'all revisions deleted' );
$rev_meta_left = (int) $wpdb->get_var( "SELECT COUNT(*) FROM wp_postmeta pm JOIN wp_posts p ON p.ID=pm.post_id WHERE p.post_type='revision'" );
eq( $rev_meta_left, 0, 'no revision meta remains' );

echo "\n[T-idempotent] cleaning again is a no-op\n";
$noop = Revisions::clean();
eq( $noop['deleted'], 0, 'no revisions left to delete' );

echo "Deleted $total revisions total (had $posts_before posts, $meta_before revision-meta rows).\n";
summary_exit();
