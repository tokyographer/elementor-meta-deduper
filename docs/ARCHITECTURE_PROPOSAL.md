# ARCHITECTURE_PROPOSAL.md

Incremental split of the single-file plugin into single-responsibility components. The main file becomes
a thin bootstrap; each concern is a class in `includes/`. No behavior is removed during the move; the
admin page and CLI become controllers that call the services.

## Component map

```
elementor-meta-deduper.php        Bootstrap: constants, autoload, hooks, activation/cron wiring
includes/
  class-database.php     DatabaseQueryException + execute_or_fail(); server flavor/version detection
  class-scanner.php      Read-only totals (accurate, no LIMIT) + display sample; targeted-key set
  class-analyzer.php     Per-post classification: identical | divergent | invalid-json; hashes
  class-planner.php      Builds a deletion PLAN (manifest rows) honoring retention policy + caps
  class-export.php       Writes/loads the manifest (JSON) — the pre-delete record
  class-executor.php     Batched, transactional deletes via execute_or_fail(); run-lock; resume
  class-verifier.php     Re-scan + cache invalidation; confirms residue == 0 for scope
  class-optimizer.php    Env-aware table rebuild; measured before/after; manual only
  class-audit.php        Structured per-run audit log (option/table); metadata + hashes only
  class-admin.php        Admin controller: scan/report/warn; small cleanups only; CLI hand-off
  class-cli.php          WP-CLI controller: scan/plan/export/clean/verify/optimize; exit codes
  class-monitor.php      Recurrence: notify-only, thresholds, trend, suppression; AS or WP-Cron
```

## Data flow

```
scan ─▶ analyze ─▶ plan ──▶ export(manifest) ──▶ execute(batched, txn) ─▶ verify ─▶ audit
                     │                                                        │
                     └── (dry-run stops here, emits plan) ───────────────────┘
optimize is a separate, manual, evidence-based step (never in the cleanup path, never cron)
monitor runs on schedule: scan + analyze only → notify; never plan/execute
```

## Key contracts

- **`Database::execute_or_fail(string $sql): int`** — the ONLY way destructive SQL runs. Returns affected
  rows or throws `DatabaseQueryException` (checks `=== false` and `$wpdb->last_error`).
- **`Scanner::totals(): array`** — every total from a dedicated aggregate query; `sample(int $limit)` is
  the only place `LIMIT` appears.
- **`Analyzer::classify(int $post_id, string $key): array`** — `{kind, copies, distinct, keep_id,
  delete_ids, hashes, sizes, json_valid, needs_review, reason}`.
- **`Planner::build(policy): Plan`** — ordered manifest rows within `max_delete`; marks `needs_review`
  posts as skipped with reason. Deterministic and idempotent.
- **`Executor::run(Plan, batch_size): Result`** — per-batch `START TRANSACTION` → `execute_or_fail` for
  each delete → `COMMIT`; on any throw `ROLLBACK` that batch and stop; records progress for `--resume`.
- **`Verifier::verify(scope): array`** — re-scan residue; `clean_post_cache()` per affected post.
- **`Optimizer::run(): array`** — detect flavor/version; measure size; rebuild; remeasure; report bytes
  actually reclaimed (evidence) or a warning if none.

## Backward compatibility

- v1.0.0 option keys (`emdd_keep_mode`, `emdd_alert_email`, `emdd_last_log`) are read on upgrade and
  migrated into the new audit/settings store.
- The Tools → Elementor Meta Deduper page and `wp elementor-dedup` command names are preserved.
- If `includes/` classes are absent (partial deploy), the bootstrap falls back to a safe no-op admin
  notice rather than fatally erroring.

## Why not a rewrite

The v1.0.0 SQL is correct; the defects are about *safety wrapping* (error handling, batching, manifest,
reporting accuracy) not about the core dedup logic. Wrapping the existing queries in these components
preserves the validated behavior while adding the guarantees, and keeps each change independently
testable against the `emdd_fixture` fixture.
