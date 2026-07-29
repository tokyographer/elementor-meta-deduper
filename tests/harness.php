<?php
/**
 * Isolated test harness. Provides a mysqli-backed $wpdb shim + minimal WP function stubs
 * so the real plugin classes run against a disposable fixture schema WITHOUT bootstrapping
 * WordPress. This guarantees destructive tests hit only the fixture schema.
 *
 * Connection is fully env-driven (no hardcoded credentials):
 *   EMDD_SOCK, EMDD_DB, EMDD_DBUSER, EMDD_DBPASS.
 *
 * Not part of the shipped plugin runtime (dev-only; export-ignored from distribution).
 */

error_reporting( E_ALL & ~E_DEPRECATED );

define( 'ABSPATH', __DIR__ . '/' );          // satisfy the classes' guard
define( 'EMDD_VERSION', 'test' );
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'WEEK_IN_SECONDS' ) ) {
	define( 'WEEK_IN_SECONDS', 604800 );
}

$GLOBALS['__emdd_opts'] = array( 'emdd_keep_mode' => 'newest' );

// ── minimal WP function stubs ────────────────────────────────────────────────
function esc_sql( $v ) { global $wpdb; return $wpdb->_escape( $v ); }
function get_option( $k, $d = false ) { return $GLOBALS['__emdd_opts'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__emdd_opts'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['__emdd_opts'][ $k ] ); return true; }
function get_transient( $k ) { return $GLOBALS['__emdd_trans'][ $k ] ?? false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['__emdd_trans'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['__emdd_trans'][ $k ] ); return true; }
function current_time( $t ) { return '2026-07-29 00:00:00'; }
function clean_post_cache( $id ) { /* no-op in harness */ }
function wp_cache_delete( $k, $g = '' ) {}
function wp_generate_uuid4() { return 'test-uuid-' . ( $GLOBALS['__emdd_uuid'] = ( $GLOBALS['__emdd_uuid'] ?? 0 ) + 1 ); }
function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); }
function trailingslashit( $s ) { return rtrim( $s, '/' ) . '/'; }
function home_url() { return 'http://local.test'; }
function wp_upload_dir() { return array( 'basedir' => sys_get_temp_dir() . '/emdd-test-uploads' ); }
function wp_mkdir_p( $d ) { return is_dir( $d ) || mkdir( $d, 0777, true ); }
function wp_get_environment_type() { return 'local'; }
function wp_get_current_user() { return (object) array( 'ID' => 0, 'user_login' => '' ); }

// ── mysqli-backed $wpdb shim (implements only what the classes use) ──────────
final class WPDB_Shim {
	public $postmeta = 'wp_postmeta';
	public $posts = 'wp_posts';
	public $options = 'wp_options';
	public $term_relationships = 'wp_term_relationships';
	public $prefix = 'wp_';
	public $last_error = '';
	public $last_query = '';
	private \mysqli $db;

	public function __construct( string $socket, string $dbname, string $user, string $pass ) {
		mysqli_report( MYSQLI_REPORT_OFF );
		$this->db = mysqli_init();
		if ( ! $this->db->real_connect( null, $user, $pass, $dbname, null, $socket ) ) {
			fwrite( STDERR, 'DB connect failed: ' . mysqli_connect_error() . "\n" );
			exit( 99 );
		}
	}

	public function _escape( $v ) { return $this->db->real_escape_string( (string) $v ); }

	public function prepare( $query, ...$args ) {
		if ( isset( $args[0] ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$i = 0;
		return preg_replace_callback( '/%[dsf]/', function ( $m ) use ( &$i, $args ) {
			$a = $args[ $i++ ] ?? '';
			if ( '%d' === $m[0] ) { return (string) (int) $a; }
			if ( '%f' === $m[0] ) { return (string) (float) $a; }
			return "'" . $this->_escape( $a ) . "'";
		}, $query );
	}

	public function query( $sql ) {
		$this->last_query = $sql;
		$this->last_error = '';
		$res = $this->db->query( $sql );
		if ( false === $res ) {
			$this->last_error = $this->db->error;
			return false;
		}
		if ( $res instanceof \mysqli_result ) {
			$n = $res->num_rows;
			$res->free();
			return $n;
		}
		return $this->db->affected_rows;
	}

	public function get_var( $sql ) {
		$r = $this->_rows( $sql );
		if ( ! $r ) { return null; }
		return array_values( $r[0] )[0];
	}
	public function get_row( $sql, $output = ARRAY_A ) {
		$r = $this->_rows( $sql );
		return $r[0] ?? null;
	}
	public function get_results( $sql, $output = ARRAY_A ) { return $this->_rows( $sql ); }
	public function get_col( $sql ) {
		$r = $this->_rows( $sql );
		return array_map( fn( $row ) => array_values( $row )[0], $r );
	}
	private function _rows( $sql ) {
		$this->last_query = $sql;
		$this->last_error = '';
		$res = $this->db->query( $sql );
		if ( false === $res ) { $this->last_error = $this->db->error; return array(); }
		if ( ! ( $res instanceof \mysqli_result ) ) { return array(); }
		$rows = $res->fetch_all( MYSQLI_ASSOC );
		$res->free();
		return $rows;
	}
}

$SOCK = getenv( 'EMDD_SOCK' ) ?: '';
$DBN  = getenv( 'EMDD_DB' ) ?: 'emdd_fixture';
$USER = getenv( 'EMDD_DBUSER' ) ?: '';
$PASS = getenv( 'EMDD_DBPASS' ) ?: '';
if ( '' === $SOCK || '' === $USER ) {
	fwrite( STDERR, "Set EMDD_SOCK, EMDD_DBUSER, EMDD_DBPASS, EMDD_DB before running the harness.\n" );
	exit( 64 );
}
$wpdb = new WPDB_Shim( $SOCK, $DBN, $USER, $PASS );
$GLOBALS['wpdb'] = $wpdb;

// ── load the real plugin classes ─────────────────────────────────────────────
$inc = dirname( __DIR__ ) . '/includes/';
foreach ( array( 'class-database', 'class-scanner', 'class-analyzer', 'class-planner', 'class-export', 'class-executor', 'class-revisions', 'class-verifier', 'class-optimizer' ) as $c ) {
	require_once $inc . $c . '.php';
}

// ── tiny assertion helpers ───────────────────────────────────────────────────
$GLOBALS['__pass'] = 0;
$GLOBALS['__fail'] = 0;
function ok( $cond, $label ) {
	if ( $cond ) { $GLOBALS['__pass']++; echo "  PASS  $label\n"; }
	else { $GLOBALS['__fail']++; echo "  FAIL  $label\n"; }
}
function eq( $a, $b, $label ) { ok( $a == $b, "$label (got " . var_export( $a, true ) . ", want " . var_export( $b, true ) . ')' ); }
function summary_exit() {
	echo "\n== {$GLOBALS['__pass']} passed, {$GLOBALS['__fail']} failed ==\n";
	exit( $GLOBALS['__fail'] > 0 ? 1 : 0 );
}
