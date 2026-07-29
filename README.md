# Elementor Meta Deduper

Safely detects and removes duplicate **single-value Elementor postmeta** rows caused by the
WPML × Elementor translation-sync bug — the defect where a translated page accumulates dozens or
hundreds of copies of its `_elementor_data` layout JSON, bloating `wp_postmeta` into the hundreds of MB
and triggering PHP "memory exhausted" fatal errors.

Works on **any** WordPress site running **Elementor** and **WPML**. No site-specific configuration.

> **This plugin cleans and monitors accumulated data. It does not patch the underlying bug.** Duplicates
> regrow until you update **SitePress + the WPML "Page Builders" glue module + Elementor**. The built-in
> monitor is there to tell you when that regrowth starts.

---

## The problem

Elementor stores an entire page as one JSON blob in a single `_elementor_data` postmeta row. A correct
save overwrites that row. In certain WPML + Elementor version combinations, the "keep translations in
sync with the original" path **inserts** a new row on every save of a translated page instead of
**updating** the existing one. Old rows are never removed, so:

- `wp_postmeta` balloons (one real site: **413 MB / 3,250 rows**, of which **2,239 rows / ~245 MB** were
  pure duplicates).
- WordPress loads *all* of a post's meta at once, so rendering an affected page pulls tens of MB of junk
  JSON into PHP → memory-exhaustion fatals.
- Backups, migrations, and admin all slow down.

See [`docs/`](docs/) for the full diagnosis, database analysis, and architecture.

## What it does

- **Scan** (read-only) with **accurate totals** — full `COUNT(*)` aggregates, never derived from a
  limited display query.
- **Classify** each affected post as **identical** (all copies byte-equal → lossless to dedup) or
  **divergent** (copies differ → needs an explicit retention policy or manual review), and as **WPML
  original / translation** (classified from the WPML tables, never assumed).
- **Plan + export a manifest** (JSON: run ID, post, row IDs, retained ID, hashes, sizes, reason) *before*
  deleting anything.
- **Clean** in batches, each batch its own transaction, with a fail-loud DB layer so a failed statement
  **rolls back** instead of silently committing a partial result. Resumable, idempotent, with a
  `--max-delete` cap and a run-lock.
- **Verify** afterward (re-scan for residue + invalidate post caches).
- **Optimize** tables with **evidence** of reclaimed space (detects MySQL vs MariaDB; applies the MySQL
  strict-mode `wp_posts` workaround only when needed). Never runs from cron.
- **Monitor** on a schedule — **notify-only**, never deletes — with thresholds, trend, and duplicate-alert
  suppression (uses Action Scheduler when WooCommerce is present, else WP-Cron).
- **Audit log** of every run (UUID, actor, environment, DB/plugin versions, counts, export path,
  verification result).

## Requirements

- WordPress 6.0+
- PHP 8.0+
- Elementor (and typically Elementor Pro)
- WPML (SitePress) with the Page Builders glue module — for the translation-aware classification

## Installation

1. Copy the `elementor-meta-deduper` folder into `wp-content/plugins/` (or upload the release zip via
   **Plugins → Add New → Upload**).
2. Activate **Elementor Meta Deduper**.
3. Open **Tools → Elementor Meta Deduper**.

## Usage

**Always take a database backup first.** Run on staging before production.

### Admin (small, targeted cleanups)

The **Tools → Elementor Meta Deduper** screen scans and reports on load, shows the worst pages, and lets
you run a **small** cleanup (capped at 500 rows). Larger runs are intentionally rejected with a pointer to
WP-CLI. A backup-confirmation checkbox is required before any destructive action.

### WP-CLI (recommended for anything non-trivial)

```bash
wp elementor-dedup scan                          # accurate totals + classification
wp elementor-dedup plan   [--include-divergent]  # dry deletion plan
wp elementor-dedup export --export=/path.json    # write manifest
wp elementor-dedup clean  --dry-run              # plan + manifest, no deletion
wp elementor-dedup clean  [--keep=newest|oldest] [--batch-size=25] [--max-delete=3000] \
                          [--post-id=123] [--translations-only] [--include-divergent] \
                          [--resume] [--yes] [--format=table|json]
wp elementor-dedup verify                        # residual check
wp elementor-dedup optimize                      # evidence-based rebuild (never cron)
```

**Exit codes:** `0` ok · `1` usage · `2` failed + rolled back · `3` partial / capped · `4` residue
remains · `5` posts need review.

## Retention policy

| Case | Behavior |
|------|----------|
| **Identical** copies | Auto-cleaned — lossless regardless of which row is kept |
| **Divergent** copies | Cleaned **only** with an explicit `--keep` policy (`newest`/`oldest`) **and** after a manifest export; otherwise flagged for review and skipped |
| Kept row is **invalid JSON** | Flagged for review, skipped |

`--keep=oldest` preserves the value WordPress currently serves (`MIN(meta_id)`); `--keep=newest` keeps the
latest synced layout. **Neither guarantees zero visual change on divergent posts** — that is why divergent
posts require an explicit decision.

## After a cleanup

- Regenerate Elementor CSS: **Elementor → Tools → Regenerate CSS & Data**
- Clear your page cache (e.g. WP Rocket) and object cache (e.g. `wp redis flush`)
- **Fix the root cause:** update SitePress + the WPML Page Builders glue module + Elementor, then re-scan
  to confirm duplicates stay at one row per page.

## Development & testing

Integration tests run the real service classes against a disposable MySQL fixture via a lightweight
`$wpdb` shim (no WordPress bootstrap), so destructive tests can only touch the fixture schema. They are
fully environment-driven — no hardcoded paths or credentials:

```bash
EMDD_SOCK=/path/to/mysqld.sock \
EMDD_DBUSER=youruser EMDD_DBPASS=yourpass \
EMDD_BACKUP=/path/to/bloated-snapshot.sql.gz \
EMDD_SRCDB=source_schema_with_wpml \
tests/run-tests.sh
```

The suite covers accurate totals, identical/divergent + translation classification, batching, caps,
idempotency, verification, and — critically — **injected-failure rollback** (a forced SQL error
mid-batch must leave all rows intact). See [`docs/TEST_PLAN.md`](docs/TEST_PLAN.md).

## Documentation

- [`docs/CODEBASE_REVIEW.md`](docs/CODEBASE_REVIEW.md) — safety audit, findings by severity
- [`docs/DATABASE_ANALYSIS.md`](docs/DATABASE_ANALYSIS.md) — real-world case study of the bug's shape
- [`docs/HARDENING_PLAN.md`](docs/HARDENING_PLAN.md) — prioritized fixes and status
- [`docs/ARCHITECTURE_PROPOSAL.md`](docs/ARCHITECTURE_PROPOSAL.md) — component design
- [`docs/TEST_PLAN.md`](docs/TEST_PLAN.md) — test matrix
- [`docs/diagnostics.sql`](docs/diagnostics.sql) — reusable read-only diagnostic queries

## Contributing

Issues and PRs welcome. Before opening a PR, run the test suite and keep changes incremental and testable.
Update the `Plugin URI` in the plugin header (`OWNER`) and `Contributors` in `readme.txt` to your own
before publishing your fork.

## License

[GPL-2.0-or-later](LICENSE).
