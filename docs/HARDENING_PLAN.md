# HARDENING_PLAN.md

Prioritized plan to take `elementor-meta-deduper` from "supervised one-off tool" to production-safe.
Incremental refactor — no big-bang rewrite. Each item lists current behavior, risk, fix, files, tests,
status. IDs map to `CODEBASE_REVIEW.md`.

Status legend: ✅ implemented this pass · ⬜ planned (follow-up).

## CRITICAL (production blockers) — implement now

| ID | Item | Fix | Files | Tests | Status |
|----|------|-----|-------|-------|--------|
| C1 | Silent query failure commits partial txn | `execute_or_fail()` + `DatabaseQueryException`; rollback on throw | `includes/class-executor.php` | T-fail, T-rollback | ✅ |
| C2 | Page totals from LIMIT query | Aggregate `COUNT(*)` totals; LIMIT for display only | `includes/class-scanner.php` | T-totals | ✅ |

## HIGH — implement now

| ID | Item | Fix | Files | Tests | Status |
|----|------|-----|-------|-------|--------|
| H1 | No batching / no cap | Batch executor: 25 posts/batch, 3,000-row cap, per-batch txn, resumable, idempotent | `class-executor.php`, `class-planner.php` | T-batch, T-cap, T-resume | ✅ |
| H2 | Destructive work in one browser request | WP-CLI-first; admin rejects cleanups over threshold | `class-cli.php`, `class-admin.php` | T-cli-exit, T-admin-threshold | ✅ |
| H3 | No cache invalidation | Finalize: `clean_post_cache()` per post; document Redis + Elementor CSS regen | `class-verifier.php` | T-cache (manual) | ✅ |
| H4 | Retention ignores identical/divergent | Analyzer classifies; auto-clean identical; divergent needs policy/flag | `class-analyzer.php` | T-identical, T-divergent | ✅ |
| H5 | No pre-delete manifest | Dry-run plan + JSON/table manifest before delete | `class-planner.php`, `class-export.php` | T-plan, T-export | ✅ |
| H6 | False success / no verification | Verifier re-scans; report reflects real counts + residue | `class-verifier.php` | T-verify | ✅ |

## MEDIUM — implement now where cheap, else follow-up

| ID | Item | Fix | Files | Tests | Status |
|----|------|-----|-------|-------|--------|
| M1 | Optimizer not env-aware; unproven reclaim | Detect MySQL/MariaDB + version; relax sql_mode only if needed; measure before/after; never cron | `class-optimizer.php` | T-optimize-evidence | ✅ |
| M2 | Recurrence guard lacks thresholds/trend | History + deltas + threshold + suppression + report link; Action Scheduler if present | `class-monitor.php` | T-monitor | ✅ |
| M3 | No concurrency guard | Single-run lock; idempotent batches | `class-executor.php` | T-concurrent | ✅ (lock) |
| M4 | Thin audit trail | Structured per-run audit log (UUID, actor, env, versions, policy, counts, export, verify, optimize) | `class-audit.php` | T-audit | ✅ |

## OPTIONAL — follow-up

| ID | Item | Status |
|----|------|--------|
| O1 | Full component split | ✅ (this pass introduces the component classes) |
| O2 | MariaDB coverage in CI | ⬜ (needs a MariaDB instance; documented in TEST_PLAN) |
| O3 | `.pot` / i18n polish | ⬜ |
| O4 | CLI flag completeness | ✅ core flags; `--format`, `--resume`, `--dry-run`, `--post-id`, `--translations-only`, `--yes` |

## Retention policy (decision, per DATABASE_ANALYSIS.md)

1. **Identical duplicates** (all copies byte-equal) → auto-clean, lossless. Keep any row (use `MIN` =
   currently served).
2. **Divergent duplicates** (2+ distinct values) → clean **only** with an explicit `--keep` policy
   (`newest`|`oldest`) AND after a manifest export; otherwise **flag for review** and skip.
3. **Invalid JSON / oversized** value on the row to keep → flag for review, skip.
4. Never assert "zero visual change" for divergent posts. `oldest` is described precisely as "preserves
   the value WordPress currently serves (`MIN(meta_id)`)", not "no change".

## Exit codes (CLI)

| Code | Meaning |
|---|---|
| 0 | Success; nothing to do, or full clean verified |
| 1 | Generic/usage error |
| 2 | Cleanup failed and rolled back (SQL error) |
| 3 | Partial: some batches done, cap reached or resumable stop |
| 4 | Verification found residual duplicates |
| 5 | Blocked: ambiguous posts require review (divergent w/o policy) |

## Rollout order

1. C1, C2 (blockers) → 2. H4/H5/H6 (retention + manifest + verify) → 3. H1/H2 (batch + CLI-first) →
4. H3/M1/M3/M4 → 5. M2. All landed behind the existing single entry file via `includes/` classes so the
active plugin keeps working throughout.
