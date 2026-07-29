# Changelog

All notable changes to this project are documented here. Format loosely follows
[Keep a Changelog](https://keepachangelog.com/); this project uses semantic-ish versioning.

## [Unreleased]

### Added
- **Revision cleanup** (`class-revisions.php`): batched, transactional deletion of post
  revisions — a major postmeta bloat source since Elementor stores a full layout copy per
  revision. Exposed as `wp elementor-dedup revisions [--dry-run]` and a separate,
  backup-gated action in the admin UI. Kept distinct from dedup; never runs from cron.

### Fixed
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
