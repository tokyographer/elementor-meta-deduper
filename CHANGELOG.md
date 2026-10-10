# Changelog

All notable changes to this project are documented here. Format loosely follows
[Keep a Changelog](https://keepachangelog.com/); this project uses semantic-ish versioning.

## [2.1.0] — 2026-10-10

### Added
- **Revision cleanup** (`class-revisions.php`): batched, transactional deletion of post
  revisions — a major postmeta bloat source since Elementor stores a full layout copy per
  revision. Exposed as `wp elementor-dedup revisions [--dry-run]` and a separate,
  backup-gated action in the admin UI. Kept distinct from dedup; never runs from cron.
  Autosaves (`<id>-autosave-v1`) are skipped because they can hold unsaved editor work.
- `Analyzer::summary()` reports `by_key` counts and plan totals report `items`
  (post+key pairs) alongside `posts`.

### Fixed
- **Planner now covers all five Elementor keys.** Scanner, Verifier and Monitor always
  counted every key, but the plan only ever targeted `_elementor_data`, so duplicates in
  `_elementor_edit_mode`, `_elementor_version`, `_elementor_template_type` and
  `_elementor_page_settings` were reported as "rows remain" yet never cleaned — the admin
  "click again" loop could never converge. Seen in production audit logs.
- JSON validity is only required for `_elementor_data`; other keys hold plain strings and
  were wrongly flagged as "needs review".
- `Verifier::verify()` now reports excess rows (same meaning as Scanner) instead of every
  row in a duplicated group, so audit entries and exit code 4 use consistent numbers.
- Admin cleanup now clears its `emdd_progress_<run_id>` option after a completed run
  instead of leaking one option per click (CLI already did this).
- Admin cleanup now processes in resumable chunks instead of rejecting jobs over the browser
  cap (which, with WP-CLI unavailable on Flywheel Local, left large sites uncleanable).

## [2.0.0]

Hardened for production use.

### Added
- Component architecture under `includes/` (scanner, analyzer, planner, executor, export, verifier,
  optimizer, audit, admin, CLI, monitor) — see `docs/ARCHITECTURE_PROPOSAL.md`.
- Fail-loud database layer: `execute_or_fail()` + `DatabaseQueryException`. A failed statement inside a
  transaction now triggers `ROLLBACK` instead of silently committing a partial result.
- Identical-vs-divergent retention policy; pre-delete JSON manifest export; per-post hashes.
- Batched, resumable, idempotent executor with `--max-delete` cap and a run-lock.
- WP-CLI-first workflow (`scan`, `plan`, `export`, `clean`, `verify`, `optimize`) with meaningful exit
  codes; admin rejects large browser-based cleanups.
- Translation-aware scanning via WPML tables (classified, not assumed).
- Environment-aware optimizer (MySQL/MariaDB detection; evidence-based reclaim; never runs from cron).
- Structured audit log; notify-only recurrence monitor with thresholds and duplicate-alert suppression
  (Action Scheduler when available).
- Integration test suite including injected-failure rollback (`tests/`).

### Changed
- Reporting now derives all totals from `COUNT(*)` aggregates; `LIMIT` is used only for the display
  sample.
- Recurrence guard is strictly notify-only (never deletes).

### Fixed
- Silent partial-commit on mid-transaction failure (critical).
- "Affected pages" total previously derived from a `LIMIT`-ed query (critical).
- Counters no longer include rows that were rolled back, and no longer seed from stale progress on a
  fresh (non-resume) run.

## [1.0.0]

- Initial release: scan, cleanup, optimize, weekly guard, WP-CLI commands.
