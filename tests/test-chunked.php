<?php
/**
 * Simulates the admin "Run cleanup" flow (chunked, resumable) against a bloated fixture,
 * proving each click makes progress and repeated clicks drive redundant rows to zero.
 */
require __DIR__ . '/harness.php';

use EMDD\Scanner;
use EMDD\Planner;
use EMDD\Executor;

$PER_REQUEST = 3000;

$start = Scanner::totals();
echo "Start: {$start['redundant_rows']} redundant rows across {$start['affected_posts']} posts\n";
ok( $start['redundant_rows'] > 0, 'fixture has duplicates to clean' );

// Simulate clicking "Run cleanup" (with include_divergent) until nothing remains.
$clicks = 0;
$total_deleted = 0;
do {
	$plan = Planner::build( array( 'keep' => 'newest', 'max_delete' => $PER_REQUEST, 'include_divergent' => true ) );
	if ( empty( $plan['items'] ) ) {
		break;
	}
	$res = Executor::run( $plan, array( 'batch_size' => 25 ) );
	ok( ! $res['stopped'], "click " . ( $clicks + 1 ) . " completed without error" );
	ok( $res['deleted_rows'] > 0, "click " . ( $clicks + 1 ) . " deleted {$res['deleted_rows']} rows (progress)" );
	$total_deleted += $res['deleted_rows'];
	++$clicks;
	$now = Scanner::totals();
	echo "  after click $clicks: {$now['redundant_rows']} rows remain\n";
	if ( $clicks > 20 ) {
		ok( false, 'too many clicks — not converging' );
		break;
	}
} while ( true );

$end = Scanner::totals();
eq( $end['redundant_rows'], 0, 'all redundant rows cleaned after chunked run' );
eq( $total_deleted, $start['redundant_rows'], 'total deleted == initial redundant rows' );

// Every affected post must now have exactly one _elementor_data row.
$dupes_left = (int) $GLOBALS['wpdb']->get_var(
	"SELECT COUNT(*) FROM (SELECT post_id FROM wp_postmeta WHERE meta_key='_elementor_data' GROUP BY post_id HAVING COUNT(*)>1) x" );
eq( $dupes_left, 0, 'no post has duplicate _elementor_data' );

echo "Converged in $clicks click(s), $total_deleted rows deleted.\n";
summary_exit();
