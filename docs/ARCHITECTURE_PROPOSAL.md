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
  class-analyzer.php     Per-(post, key) classification: identical | divergent | invalid-json; hashes
  class-planner.php      Builds a deletion PLAN over ALL five keys honoring retention policy + caps
  class-export.php       Writes/loads the manifest (JSON) — the pre-delete record
  class-executor.php     Batched, transactional deletes via execute_or_fail(); run-lock; resume
  class-revisions.php    Separate revision cleanup (scan + batched delete); skips autosaves; never cron
  class-verifier.php     Re-scan + cache invalidation; residual EXCESS rows (same meaning as Scanner)
  class-optimizer.php    Env-aware table rebuild; measured before/after; manual only
  class-audit.php        Structured per-run audit log (option/table); metadata + hashes only
  class-admin.php        Admin controller: scan/report/warn; chunked cleanups; revision cleanup
  class-cli.php          WP-CLI controller: scan/plan/export/clean/revisions/verify/optimize; exit codes
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
- **`Analyzer::classify(int $post_id, string $key, string $keep_mode): array`** — `{kind, copies,
  distinct, keep_id, delete_ids, keep_hash, json_valid, needs_review, reason, language, translation}`.
  `json_valid` is only enforced for `Database::JSON_KEYS` (`_elementor_data`); other keys hold plain
  strings.
- **`Planner::build(policy): Plan`** — one item per affected (post, key) pair across every key in
  `Database::KEYS`, ordered, within `max_delete` (whole-item atomic); `needs_review` items skipped with
  reason. `totals.posts` counts distinct posts, `totals.items` counts pairs. Deterministic and idempotent.
  Invariant: the plan targets exactly what `Scanner::totals()` counts, so repeated runs converge to zero.
- **`Executor::run(Plan, batch_size): Result`** — per-batch `START TRANSACTION` → `execute_or_fail` for
  each delete → `COMMIT`; on any throw `ROLLBACK` that batch and stop; records progress for `--resume`.
- **`Verifier::verify(scope): array`** — re-scan residual excess rows (`SUM(c-1)`, identical to the
  scanner's `redundant_rows`); `clean_post_cache()` per affected post.
- **`Revisions::scan()` / `Revisions::clean(opts)`** — separate from the dedup path. Deletes revisions
  (meta + term relationships + post) in per-batch transactions through `execute_or_fail()`; capped;
  resumable by repeat. Autosaves (`post_name LIKE '%-autosave-v1'`) are excluded because they can hold
  unsaved editor work. The LIMIT is interpolated as a cast int rather than via `$wpdb->prepare()`, since
  the literal `%` in the autosave filter would otherwise be read as a placeholder.
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
