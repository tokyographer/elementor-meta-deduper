<?php
/**
 * Class-level integration tests against emdd_fixture.
 * Covers: accurate totals, identical/divergent classification, translation classification,
 * planner caps + retention, JSON validity, batched execution, verification, big post.
 */
require __DIR__ . '/harness.php';

use EMDD\Scanner;
use EMDD\Analyzer;
use EMDD\Planner;
use EMDD\Executor;
use EMDD\Verifier;
use EMDD\Database;

echo "\n[T-totals] accurate totals (independent of display LIMIT)\n";
$t = Scanner::totals();
eq( $t['redundant_rows'], 2239, 'redundant_rows full count' );
eq( $t['affected_posts'], 83, 'affected_posts full count' );
ok( $t['redundant_bytes'] > 250000000, 'redundant_bytes ~245MB' );
$sample = Scanner::sample( 10 );
eq( count( $sample ), 10, 'sample respects LIMIT 10' );
ok( $t['affected_posts'] > count( $sample ), 'totals not derived from sample' );

echo "\n[T-summary] identical vs divergent + translation classification\n";
$s = Analyzer::summary( 'newest' );
eq( $s['identical'], 42, 'identical posts' );
eq( $s['divergent'], 41, 'divergent posts' );
eq( $s['translations'], 83, 'all affected are translations' );
eq( $s['originals'], 0, 'no originals affected' );

echo "\n[T-identical] post 6747 is identical (150 copies, 1 distinct)\n";
$c = Analyzer::classify( 6747, '_elementor_data', 'newest' );
eq( $c['kind'], 'identical', '6747 kind' );
eq( $c['copies'], 150, '6747 copies' );
eq( count( $c['delete_ids'] ), 149, '6747 delete_ids' );
ok( $c['translation'], '6747 is translation' );

echo "\n[T-divergent] post 11743 is divergent (152 copies, 32 distinct)\n";
$d = Analyzer::classify( 11743, '_elementor_data', 'newest' );
eq( $d['kind'], 'divergent', '11743 kind' );
eq( $d['distinct'], 32, '11743 distinct values' );
ok( $d['json_valid'], '11743 kept row is valid JSON' );

echo "\n[T-plan-retention] default plan skips divergent, keeps identical\n";
$plan = Planner::build( array( 'keep' => 'newest', 'max_delete' => 100000 ) );
eq( $plan['totals']['posts'], 42, 'plan covers 42 identical posts' );
eq( $plan['skipped_review'], 41, 'plan skips 41 divergent for review' );

echo "\n[T-plan-divergent] include-divergent brings in all 83\n";
$plan2 = Planner::build( array( 'keep' => 'newest', 'max_delete' => 100000, 'include_divergent' => true ) );
eq( $plan2['totals']['posts'], 83, 'plan covers all 83 with policy' );
eq( $plan2['totals']['delete_rows'], 2239, 'plan delete_rows == redundant_rows' );

echo "\n[T-cap] max-delete cap stops early, whole-post atomic\n";
$capped = Planner::build( array( 'keep' => 'newest', 'max_delete' => 200, 'include_divergent' => true ) );
ok( $capped['capped'], 'cap flagged' );
ok( $capped['totals']['delete_rows'] <= 200, 'cap respected (<=200)' );

echo "\n[T-bigpost+batch+execute] execute identical-only plan, batched\n";
$before = (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM wp_postmeta WHERE meta_key='_elementor_data'" );
$res = Executor::run( $plan, array( 'batch_size' => 10 ) );
ok( ! $res['stopped'], 'run completed' );
eq( $res['deleted_rows'], $plan['totals']['delete_rows'], 'deleted == planned' );
$after = (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM wp_postmeta WHERE meta_key='_elementor_data'" );
eq( $before - $after, $plan['totals']['delete_rows'], 'row delta matches' );

echo "\n[T-verify] identical posts now single; divergent remain (untouched)\n";
$dup_left = (int) $GLOBALS['wpdb']->get_var(
	"SELECT COUNT(*) FROM (SELECT post_id FROM wp_postmeta WHERE meta_key='_elementor_data' GROUP BY post_id HAVING COUNT(*)>1) x" );
eq( $dup_left, 41, '41 divergent posts still duplicated (correctly skipped)' );
$v = Verifier::verify();
eq( $v['residual_posts'], 41, 'verifier reports 41 residual (divergent)' );

echo "\n[T-idempotent] re-running same plan deletes nothing new\n";
$res2 = Executor::run( $plan, array( 'batch_size' => 10 ) );
eq( $res2['deleted_rows'], 0, 'idempotent re-run deletes 0' );

summary_exit();
