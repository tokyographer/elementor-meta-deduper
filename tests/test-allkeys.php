<?php
/**
 * Regression test for the all-keys planning gap (2.1.0): duplicates in keys other than
 * _elementor_data must be planned and cleaned, and Scanner/Verifier must agree on "excess".
 */
require __DIR__ . '/harness.php';

use EMDD\Scanner;
use EMDD\Analyzer;
use EMDD\Planner;
use EMDD\Executor;
use EMDD\Verifier;

$wpdb = $GLOBALS['wpdb'];

// Seed: one post with 3 identical _elementor_version rows and 2 identical _elementor_edit_mode rows.
$pid = (int) $wpdb->get_var( "SELECT MAX(post_id) FROM wp_postmeta" ) + 1000;
$wpdb->query( "DELETE FROM wp_postmeta WHERE post_id={$pid}" );
foreach ( array( '4.3.4', '4.3.4', '4.3.4' ) as $v ) {
	$wpdb->query( "INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES ({$pid}, '_elementor_version', '{$v}')" );
}
foreach ( array( 'builder', 'builder' ) as $v ) {
	$wpdb->query( "INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES ({$pid}, '_elementor_edit_mode', '{$v}')" );
}

echo "\n[T-classify] plain-string keys are not 'needs review'\n";
$c = Analyzer::classify( $pid, '_elementor_version', 'newest' );
eq( $c['kind'], 'identical', 'version rows identical' );
ok( $c['json_valid'], 'json_valid is true for non-JSON key' );
ok( ! $c['needs_review'], 'not flagged for review' );
eq( count( $c['delete_ids'] ), 2, 'two excess version rows' );

echo "\n[T-plan] plan restricted to the seeded post covers both keys\n";
$plan = Planner::build( array( 'keep' => 'newest', 'max_delete' => 100, 'post_id' => $pid ) );
eq( $plan['totals']['items'], 2, 'two post+key items' );
eq( $plan['totals']['posts'], 1, 'one distinct post' );
eq( $plan['totals']['delete_rows'], 3, 'three excess rows planned (2 + 1)' );
$keys = array_column( $plan['items'], 'meta_key' );
ok( in_array( '_elementor_version', $keys, true ) && in_array( '_elementor_edit_mode', $keys, true ), 'both keys present' );

echo "\n[T-agree] Scanner and Verifier count the same excess\n";
$t = Scanner::totals();
$v = Verifier::verify();
eq( $v['residual_rows'], $t['redundant_rows'], 'verifier residual == scanner redundant' );

echo "\n[T-execute] cleanup leaves exactly one row per key\n";
$res = Executor::run( $plan, array( 'batch_size' => 10 ) );
ok( ! $res['stopped'], 'run completed' );
eq( $res['deleted_rows'], 3, 'deleted three rows' );
eq( (int) $wpdb->get_var( "SELECT COUNT(*) FROM wp_postmeta WHERE post_id={$pid} AND meta_key='_elementor_version'" ), 1, 'one version row left' );
eq( (int) $wpdb->get_var( "SELECT COUNT(*) FROM wp_postmeta WHERE post_id={$pid} AND meta_key='_elementor_edit_mode'" ), 1, 'one edit_mode row left' );
eq( (string) $wpdb->get_var( "SELECT meta_value FROM wp_postmeta WHERE post_id={$pid} AND meta_key='_elementor_version'" ), '4.3.4', 'kept value intact' );

echo "\n[T-converge] full plan over the fixture drives totals to zero across ALL keys\n";
$clicks = 0;
do {
	$p = Planner::build( array( 'keep' => 'newest', 'max_delete' => 3000, 'include_divergent' => true ) );
	if ( empty( $p['items'] ) ) {
		break;
	}
	$r = Executor::run( $p, array( 'batch_size' => 25 ) );
	ok( ! $r['stopped'], 'click ' . ( ++$clicks ) . ' ok' );
} while ( $clicks < 50 );
$end = Scanner::totals();
eq( $end['redundant_rows'], 0, 'scanner reports zero redundant rows across all keys' );
ok( Verifier::verify()['clean'], 'verifier clean' );

$wpdb->query( "DELETE FROM wp_postmeta WHERE post_id={$pid}" );
summary_exit();
