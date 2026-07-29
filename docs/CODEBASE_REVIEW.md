# CODEBASE_REVIEW.md

Audit of `elementor-meta-deduper` v1.0.0 (single-file `elementor-meta-deduper.php` + `uninstall.php`)
against production-safety criteria. Findings are ordered Critical → High → Medium → Optional. Each maps
to a fix in `HARDENING_PLAN.md`.

## Summary verdict

The v1.0.0 plugin is a correct *tool for a one-off, supervised cleanup on a small/known dataset* (which
is how it was used here). It is **not production-safe for unattended or large-scale use**: its transaction
can commit partial results after a silent query failure, and a key report figure is derived from a
`LIMIT`ed query. Those two alone are release blockers.

---

## CRITICAL

### C1 — `$wpdb->query()` failures are invisible; partial transaction can COMMIT
**Current behavior:** `emdd_cleanup()` wraps deletes in `START TRANSACTION … COMMIT` inside a
`try/catch (\Throwable)`. But `$wpdb->query()` **does not throw** — on error it returns `false`. The code
does `(int) $wpdb->query(...)`, so a failed DELETE becomes `0`, the `catch` never fires, and execution
falls through to `COMMIT`. A deadlock, lock-wait timeout, or disconnect on step 2 commits step 1's
partial deletion and reports success.
**Risk:** silent data loss / partial cleanup reported as complete. Highest-severity defect.
**Fix:** `execute_or_fail()` that checks `=== false` and inspects `$wpdb->last_error`, throwing
`DatabaseQueryException`; keep the transaction, so the throw triggers `ROLLBACK`.
**Files:** main plugin (executor). **Tests:** injected-failure rollback (T-fail, T-rollback).

### C2 — Page count derived from a LIMIT-ed display query
**Current behavior:** `emdd_scan()` sets `$per_page` with `... HAVING copies>1 ... LIMIT 50` and then
`$dupe_pages = count($per_page)`. On any site with more than 50 affected pages, "affected pages" is
capped at 50 and under-reports. Cleanup decisions and the admin warning read from this.
**Risk:** operator under-estimates blast radius; “affected pages” is simply wrong at scale.
**Fix:** compute totals with dedicated `COUNT(*)` aggregate queries; use `LIMIT` only for the display
sample. **Files:** scanner. **Tests:** accurate-totals (T-totals).

---

## HIGH

### H1 — No batching, no deletion cap
One monolithic multi-join `DELETE`. At 2,239 rows it was fine; at 10× it risks lock-wait timeouts, huge
InnoDB undo-log growth, and replication lag. No `--max-delete` guardrail.
**Fix:** batch executor (default 25 posts/batch, 3,000-row cap), each batch its own transaction, resumable.

### H2 — Destructive work runs in a single browser request
Cleanup + `OPTIMIZE` execute synchronously in an admin POST. Large runs will hit PHP
`max_execution_time` / gateway timeouts, leaving state unknown.
**Fix:** WP-CLI-first; admin refuses cleanups above a threshold and points to CLI.

### H3 — No cache invalidation after delete
After removing meta rows, the code never calls `clean_post_cache()`, `wp_cache_flush()`, or clears
Elementor CSS. With Redis object cache active, stale `post_meta` cache can still reference deleted rows.
**Fix:** verification/finalize step invalidates post meta cache for affected posts + notes Elementor CSS
regen; document Redis flush.

### H4 — Retention ignores identical-vs-divergent; “zero visual change” overclaimed
`emdd_cleanup()` keeps `MAX`/`MIN` globally. Per `DATABASE_ANALYSIS.md`, **41 of 83** affected posts are
divergent and MIN≠MAX — so “newest” changes rendered content on ~half, and even “oldest” is only
“preserves currently-served value”, not guaranteed-safe in principle.
**Fix:** analyzer classifies identical/divergent; auto-clean identical; divergent only under explicit
policy or flagged for review; never assert zero-change unconditionally.

### H5 — No pre-delete manifest / export
Rows are deleted with no exportable record of what was removed. No undo beyond a full DB restore.
**Fix:** dry-run plan + JSON/table manifest (run ID, post, row IDs, retained ID, hashes, sizes, reason)
written before any delete.

### H6 — False success / no post-cleanup verification
Because failures are invisible (C1), the success notice can print counts that didn’t happen. There is no
re-scan proving duplicates are gone.
**Fix:** verification service re-runs the scan and confirms 0 remaining for the targeted scope; report
reflects actual affected-row counts and any residue.

---

## MEDIUM

### M1 — Optimizer not environment-aware; unconditional `sql_mode=''`; unproven reclaim
`emdd_optimize()` always runs `SET SESSION sql_mode=''` and claims space reclaimed with no measurement.
It doesn’t detect MySQL vs MariaDB (the `wp_posts` zero-date error is MySQL-strict-specific).
**Fix:** detect server flavor/version, only relax `sql_mode` when the strict-mode error is present,
measure `.ibd`/information_schema before & after, report evidence, keep it off cron.

### M2 — Recurrence guard lacks thresholds / suppression / trend
`emdd_guard_run()` emails whenever `dupe_rows>0`, every week, with no dedupe of identical alerts, no
previous-vs-current delta, no newly-affected list. Uses WP-Cron though WooCommerce (Action Scheduler) is
present and more reliable.
**Fix:** store history; alert on threshold/growth; suppress duplicate alerts; include deltas + report
link; prefer Action Scheduler when available. (Guard stays notify-only — that part is already correct.)

### M3 — Concurrency: no guard against saves/WPML sync mid-cleanup
A translated page saved during cleanup can re-insert rows; nothing serializes runs.
**Fix:** single-run lock (transient/`GET_LOCK`); batches are idempotent so a concurrent insert is simply
caught next run.

### M4 — Thin audit trail
Only `emdd_last_log` (counts + time). No UUID, actor, env, DB/plugin versions, policy, export path,
verification/optimization outcome.
**Fix:** structured audit log per run (see HARDENING_PLAN §9).

---

## OPTIONAL / LOW

- **O1** — Monolithic single file; responsibilities not separated (scanner/analyzer/executor/etc.).
  Refactor incrementally per `ARCHITECTURE_PROPOSAL.md` (no big-bang rewrite).
- **O2** — Self-referencing `DELETE … JOIN (SELECT … FROM same table)` is safe on MySQL 8.4 (derived
  table is materialized) but should be covered by a test on MariaDB too.
- **O3** — Text domain loaded implicitly; no `.pot`. Cosmetic.
- **O4** — WP-CLI `clean` lacks `--dry-run`, `--post-id`, `--translations-only`, `--yes`, exit codes.

## What is already correct (preserve)

- Nonce + `manage_options` checks on every destructive admin action.
- Whitelisted single-value key list (validated: only these 5, all genuinely single-value).
- Cron guard is **notify-only** (does not auto-delete) — keep this property.
- Uninstall cleans options + unschedules cron; never touches content.
- `--keep oldest` correctly maps to `MIN(meta_id)` = the WP-served value.
