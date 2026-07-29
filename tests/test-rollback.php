<?php
/**
 * T-fail / T-rollback: inject a controlled SQL failure mid-transaction and prove the
 * batch rolls back atomically — including a post that was successfully deleted earlier
 * in the SAME batch (guards against C1: partial success committing).
 */
require __DIR__ . '/harness.php';

use EMDD\Analyzer;
use EMDD\Executor;

$wpdb = $GLOBALS['wpdb'];

// Two identical-copy posts; 6747 processed first (will succeed pre-commit), 7119 second (forced to fail).
$POST_OK   = 6747;
$POST_FAIL = 7119;

$a = Analyzer::classify( $POST_OK, '_elementor_data', 'newest' );
$b = Analyzer::classify( $POST_FAIL, '_elementor_data', 'newest' );
ok( 'identical' === $a['kind'] && 'identical' === $b['kind'], 'both test posts are identical-copy' );

$ok_before   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM wp_postmeta WHERE post_id=$POST_OK AND meta_key='_elementor_data'" );
$fail_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM wp_postmeta WHERE post_id=$POST_FAIL AND meta_key='_elementor_data'" );

// Inject failure: any DELETE of the fail-post's _elementor_data rows raises an error.
$wpdb->query( "DROP TRIGGER IF EXISTS emdd_fail" );
$wpdb->query(
	"CREATE TRIGGER emdd_fail BEFORE DELETE ON wp_postmeta FOR EACH ROW
	 BEGIN
	   IF OLD.post_id=$POST_FAIL AND OLD.meta_key='_elementor_data' THEN
	     SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected failure';
	   END IF;
	 END"
);

// Hand-built plan: both posts in ONE batch, ordered OK then FAIL.
$plan = array(
	'run_id' => 'rollback-test',
	'keep'   => 'newest',
	'items'  => array(
		array( 'post_id' => $POST_OK,   'meta_key' => '_elementor_data', 'delete_ids' => $a['delete_ids'], 'delete_bytes' => $a['delete_bytes'] ),
		array( 'post_id' => $POST_FAIL, 'meta_key' => '_elementor_data', 'delete_ids' => $b['delete_ids'], 'delete_bytes' => $b['delete_bytes'] ),
	),
);

$res = Executor::run( $plan, array( 'batch_size' => 10 ) ); // both in same batch

ok( $res['stopped'], 'run stopped on failure' );
ok( ! empty( $res['error'] ) && str_contains( $res['error'], 'injected failure' ), 'DatabaseQueryException surfaced injected error' );
eq( $res['failed_batch'], 0, 'failed batch index recorded' );

$ok_after   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM wp_postmeta WHERE post_id=$POST_OK AND meta_key='_elementor_data'" );
$fail_after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM wp_postmeta WHERE post_id=$POST_FAIL AND meta_key='_elementor_data'" );

eq( $ok_after, $ok_before, 'ROLLBACK restored the already-deleted post in the same batch (no partial commit)' );
eq( $fail_after, $fail_before, 'failing post untouched' );
eq( $res['deleted_rows'], 0, 'reported deleted_rows == 0 after rollback (no false success)' );

// cleanup trigger
$wpdb->query( "DROP TRIGGER IF EXISTS emdd_fail" );

summary_exit();
