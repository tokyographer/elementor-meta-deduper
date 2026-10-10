# TEST_PLAN.md

Test strategy for the hardened plugin. Primary integration fixture is the **local** database, restored
into the isolated schema **`emdd_fixture`** (never production). A fresh backup is taken before any
destructive test; each destructive test restores the fixture first so tests are independent.

## Fixtures

- **`emdd_fixture`** — pre-cleanup snapshot: 3,250 `_elementor_data` rows / 1,011 posts, 83 duplicated
  (42 identical, 41 divergent), all translations, all JSON valid. + `wp_icl_translations`.
- **Synthetic mini-fixtures** (built by `tests/fixture.sql`) for edge cases hard to find in real data:
  invalid JSON, custom prefix, >100 copies on one post, concurrent-insert simulation.
- **MariaDB** — not available in this Local (MySQL 8.4 only). MariaDB cases are specified here and marked
  ⬜ pending a MariaDB instance; the flavor-detection code path is unit-tested with a mocked version.

## Test matrix

| ID | Scenario | Type | Expected | Status |
|----|----------|------|----------|--------|
| T-none | No duplicates (clean `local`) | integration | scan totals all 0; clean = no-op; exit 0 | ✅ |
| T-identical | Identical duplicates (post 6747) | integration | classified IDENTICAL; auto-cleaned lossless | ✅ |
| T-divergent | Divergent duplicates (post 11743) | integration | classified DIVERGENT; skipped w/o policy (exit 5); cleaned w/ `--keep` | ✅ |
| T-invalidjson | Invalid JSON value on keep-row | integration | flagged needs_review; skipped | ✅ |
| T-prefix | Custom table prefix | integration | uses `$wpdb->prefix`; no hardcoded `wp_` | ✅ |
| T-mariadb | MariaDB server | integration | flavor detected; sql_mode not forced | ⬜ |
| T-mysql | MySQL 8.4 | integration | flavor detected; `wp_posts` workaround applied only if strict-mode error | ✅ |
| T-bigpost | >100 copies on one post (11743=152) | integration | batched; all excess removed; 1 row remains | ✅ |
| T-fail | SQL failure during deletion (injected) | integration | `DatabaseQueryException` thrown; batch ROLLBACK | ✅ |
| T-rollback | Rollback leaves data intact | integration | affected post row count unchanged after failure | ✅ |
| T-interrupt | Interrupted run (kill mid-batch) | integration | progress recorded; no partial-committed batch | ✅ |
| T-resume | `--resume` continues | integration | remaining batches complete; idempotent | ✅ |
| T-cap | `--max-delete` cap reached | integration | stops at cap; exit 3; resumable | ✅ |
| T-concurrent | Insert during cleanup | integration | run-lock or next-run idempotency catches new row | ✅ |
| T-translation | Translation classification | integration | 83 translations, 0 originals reported (not assumed) | ✅ |
| T-totals | Accurate totals vs LIMIT | integration | totals from COUNT(*), independent of display sample | ✅ |
| T-plan | Dry-run plan/manifest | integration | manifest lists row IDs, keep IDs, hashes, reasons; no rows deleted | ✅ |
| T-export | Manifest export JSON | integration | valid JSON written; reloadable | ✅ |
| T-verify | Post-clean verification | integration | residue 0 for scope; caches invalidated | ✅ |
| T-optimize-evidence | Optimizer reports real reclaim | integration | before/after bytes measured; no false claim | ✅ |
| T-cli-exit | CLI exit codes | integration | 0/2/3/4/5 per HARDENING_PLAN | ✅ |
| T-admin-chunked | Admin chunked cleanup converges | integration | repeated 3,000-row "clicks" drive redundant rows to 0 (`test-chunked.php`) | ✅ |
| T-revisions | Revision cleanup | integration | cap respected; repeat-until-zero; meta removed; idempotent; autosaves untouched (`test-revisions.php`) | ✅ |
| T-allkeys | Duplicates in keys other than `_elementor_data` | integration | planned + cleaned; plain-string keys not "needs review"; Scanner and Verifier agree on excess (`test-allkeys.php`) | ✅ |
| T-admin-auth | Nonce + capability checks | integration | missing nonce / non-admin → denied | ✅ |
| T-audit | Audit record completeness | integration | UUID, actor, env, versions, counts, export, verify present | ✅ |
| T-monitor | Recurrence notify-only + threshold | integration | emails on growth over threshold; never deletes; suppresses dupes | ✅ |

## Harness

- `tests/run-tests.sh` — bash runner using the direct `mysql` client + socket (no WordPress bootstrap).
  Restores `emdd_fixture` before each destructive file, then runs `tests.php`, `test-rollback.php`,
  `test-chunked.php`, `test-revisions.php` and `test-allkeys.php` through `tests/harness.php`, a
  mysqli-backed `$wpdb` shim with minimal WP function stubs, so the real service classes execute
  against the fixture only. Fully env-driven: `EMDD_SOCK`, `EMDD_DB`, `EMDD_DBUSER`, `EMDD_DBPASS`,
  `EMDD_BACKUP`, optional `EMDD_SRCDB`.
- Fixture-bound counts in `tests.php` (83 posts, 42/41 split, 2,239 rows) describe the original
  `_elementor_data`-only snapshot. Since 2.1.0 plans span all five keys, so a fixture that also holds
  duplicates in the other keys will shift the plan's `posts`/`delete_rows` expectations.
- Failure injection (T-fail): a batch is pointed at a non-existent column / a `BEFORE DELETE` trigger
  raises `SIGNAL SQLSTATE '45000'`, forcing `$wpdb->query()` to return false mid-transaction.

## Non-negotiables

- **Never** run destructive SQL against `local` (working copy) or production. Destructive tests target
  `emdd_fixture` only.
- Every destructive test is preceded by a fixture restore, so ordering never matters.
- A test that cannot restore its fixture must abort the suite (fail closed).
