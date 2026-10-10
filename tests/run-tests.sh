#!/usr/bin/env bash
# Integration tests for elementor-meta-deduper against a disposable fixture schema.
# Restores the fixture before each destructive test. NEVER targets a live/production DB.
#
# These are maintainer integration tests tied to a MySQL fixture. Configure via env vars:
#   EMDD_PHP     path to the php binary            (default: `php` on PATH)
#   EMDD_MYSQL   path to the mysql client binary   (default: `mysql` on PATH)
#   EMDD_SOCK    MySQL socket                       (required)
#   EMDD_DB      fixture schema name                (default: emdd_fixture)
#   EMDD_DBUSER  fixture user                       (required)
#   EMDD_DBPASS  fixture password                   (required)
#   EMDD_BACKUP  gzipped SQL dump of a BLOATED wp_postmeta/wp_posts/wp_options (required)
#   EMDD_SRCDB   optional source schema to copy wp_icl_translations/wp_icl_languages from
#
# Example (Local by Flywheel):
#   EMDD_SOCK=/path/to/mysqld.sock EMDD_BACKUP=/path/to/predup.sql.gz ./run-tests.sh
set -uo pipefail

PHP="${EMDD_PHP:-php}"
MYSQL="${EMDD_MYSQL:-mysql}"
SOCK="${EMDD_SOCK:-}"
DB="${EMDD_DB:-emdd_fixture}"
DBUSER="${EMDD_DBUSER:-}"
DBPASS="${EMDD_DBPASS:-}"
BACKUP="${EMDD_BACKUP:-}"
SRCDB="${EMDD_SRCDB:-}"

if [ -z "$SOCK" ] || [ -z "$BACKUP" ] || [ -z "$DBUSER" ]; then
  echo "ERROR: set EMDD_SOCK, EMDD_DBUSER, EMDD_DBPASS and EMDD_BACKUP (see header of this script)." >&2
  exit 64
fi

export EMDD_SOCK="$SOCK" EMDD_DB="$DB" EMDD_DBUSER="$DBUSER" EMDD_DBPASS="$DBPASS"
HARNESS_DIR="$(cd "$(dirname "$0")" && pwd)"
MY(){ "$MYSQL" -u "$DBUSER" "-p${DBPASS}" --socket="$SOCK" "$@" 2>/dev/null; }

restore_fixture(){
  MY -e "DROP DATABASE IF EXISTS \`$DB\`; CREATE DATABASE \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci;"
  gunzip -c "$BACKUP" | "$MYSQL" -u "$DBUSER" "-p${DBPASS}" --socket="$SOCK" "$DB" 2>/dev/null
  if [ -n "$SRCDB" ]; then
    for t in wp_icl_translations wp_icl_languages; do
      "${EMDD_MYSQLDUMP:-mysqldump}" -u "$DBUSER" "-p${DBPASS}" --socket="$SOCK" --no-tablespaces "$SRCDB" "$t" 2>/dev/null \
        | "$MYSQL" -u "$DBUSER" "-p${DBPASS}" --socket="$SOCK" "$DB" 2>/dev/null
    done
  fi
}

echo "### Restoring fixture ###"
restore_fixture
echo "### Running class-level tests ###"
"$PHP" "$HARNESS_DIR/tests.php"
RC=$?

echo
echo "### Injected-failure rollback test (separate, restores fixture) ###"
restore_fixture
"$PHP" "$HARNESS_DIR/test-rollback.php"
RC2=$?

echo
echo "### Chunked cleanup test (restores fixture) ###"
restore_fixture
"$PHP" "$HARNESS_DIR/test-chunked.php"
RC3=$?

echo
echo "### Revision cleanup test (restores fixture) ###"
restore_fixture
"$PHP" "$HARNESS_DIR/test-revisions.php"
RC4=$?

echo
echo "### All-keys planning regression test (restores fixture) ###"
restore_fixture
"$PHP" "$HARNESS_DIR/test-allkeys.php"
RC5=$?

echo
TOTAL=$(( RC + RC2 + RC3 + RC4 + RC5 ))
if [ $TOTAL -eq 0 ]; then echo "ALL TESTS PASSED"; else echo "TEST FAILURES (tests=$RC rollback=$RC2 chunked=$RC3 revisions=$RC4 allkeys=$RC5)"; fi
exit $TOTAL
