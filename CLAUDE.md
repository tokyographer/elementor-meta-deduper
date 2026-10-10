# Elementor Meta Deduper

## Purpose
WordPress plugin that detects and safely removes duplicate single-value Elementor postmeta rows left by the
WPML × Elementor translation-sync bug, plus a separate revision cleaner. Built for WPML + Elementor sites;
WP-CLI-first, with a chunked admin fallback. It cleans accumulated data; it does not patch the bug.

## Architecture
Thin bootstrap (`elementor-meta-deduper.php`) loads single-responsibility classes from `includes/`:

- `class-database.php` → `KEYS` whitelist, `JSON_KEYS`, `execute_or_fail()` (the only way destructive SQL runs), run lock
- `class-scanner.php` → accurate totals over all keys (COUNT(*), never from LIMIT); `sample()` is the only LIMIT
- `class-analyzer.php` → per-(post, key) classification: identical | divergent | needs_review; WPML language lookup
- `class-planner.php` → deletion plan over ALL `KEYS`, capped, whole-item atomic; this is what gets exported and executed
- `class-export.php` → JSON manifest under `uploads/emdd-manifests/` before any delete
- `class-executor.php` → per-batch transactions, rollback on throw, progress option for `--resume`
- `class-revisions.php` → separate revision cleanup; skips autosaves; never cron
- `class-verifier.php` → residual excess rows (same meaning as scanner) + cache invalidation
- `class-optimizer.php` → evidence-based OPTIMIZE; MariaDB/MySQL aware; manual only
- `class-audit.php` → ring buffer of 50 run records in `emdd_audit_log`
- `class-monitor.php` → weekly scan + email, notify-only; Action Scheduler when present, else WP-Cron
- `class-admin.php` → Tools page: chunked cleanup (3,000 rows/click), revision cleanup, settings
- `class-cli.php` → `wp elementor-dedup scan|plan|export|clean|revisions|verify|optimize`; exit codes 0–5

Data flow: scan → analyze → plan → export manifest → execute (batched txn) → verify → audit.

## Run Order
No build step. Install the folder into `wp-content/plugins/` and activate, or build a zip:

    git archive --format=zip --prefix=elementor-meta-deduper/ -o elementor-meta-deduper-<ver>.zip HEAD

Tests (integration, need a MySQL fixture; never point at a live DB):

    EMDD_SOCK=... EMDD_DBUSER=... EMDD_DBPASS=... EMDD_BACKUP=bloated.sql.gz tests/run-tests.sh

Lint only: `for f in *.php includes/*.php tests/*.php; do php -l "$f"; done`

## Hardware & Environment
- PHP 8.0+ (developed on 8.5 CLI), WordPress 6.0+, MySQL 8 or MariaDB 10.6+/11.x
- No Composer, no Node, no external dependencies
- Production sites seen: Plesk + MariaDB 11, WP Toolkit cron takeover, Redis object cache

## Key Dependencies & Gotchas
- `$wpdb->prepare()` treats a literal `%` as a placeholder; the revisions autosave filter (`%-autosave-v1`)
  must never pass through it. Cast ints and interpolate instead.
- Scanner, Verifier and Monitor count all five keys. The Planner MUST cover the same keys or the admin
  "rows remain, click again" loop never converges (this was the 2.0.0 → 2.1.0 bug).
- Only `_elementor_data` is JSON. `_elementor_page_settings` is PHP-serialized; the rest are plain strings.
- InnoDB does not shrink table files after DELETE; `wp db size` only drops after `optimize`.
- Deleting revisions removes the Elementor editor's revision history for those pages by design.
- The harness `$wpdb` shim's `prepare()` only handles `%d %s %f`; real `wpdb` is stricter.
- `tests/tests.php` hard-codes counts from a specific fixture snapshot (83 posts, 2,239 rows).

## Configuration
Options (all under `emdd_*`): `emdd_keep_mode` newest|oldest, `emdd_alert_email`, `emdd_alert_threshold`.
No `.env` or `config.toml`; the plugin has no secrets. Manifests land in `uploads/emdd-manifests/`
(listing blocked via index.php + .htaccess).

## Never Do
- Never run destructive SQL outside `Database::execute_or_fail()` inside a transaction
- Never let the Monitor or any cron path plan or delete
- Never derive totals from a LIMITed query
- Never widen `Database::KEYS` without checking the value type and adding it to `JSON_KEYS` if JSON
- Never commit anything in `vault/` (gitignored client DB dumps and installed-plugin zips)
- Never run `tests/run-tests.sh` against a live or working-copy database
- Never store full Elementor JSON in the audit log or manifest (IDs, hashes, sizes only)

## Current Status
- 2.1.0 (2026-10-10): all-keys planning fix, verifier semantics, admin progress cleanup, autosave-safe
  revision cleaner, regression test `tests/test-allkeys.php`. Release zip built from the working tree
  into `vault/`; integration suite not yet re-run on the fixture after these changes.
- Known: `Scanner::sample()` and the admin "worst pages" table only look at `_elementor_data`.
- Known: `Analyzer::wpml_info()` issues a `SHOW TABLES` per post; fine for hundreds, slow for tens of thousands.

## Future Integration
Standalone WordPress plugin; talks to nothing else. Outputs: JSON manifests (`uploads/emdd-manifests/`),
`emdd_audit_log` option, alert emails. Input: the site's own `wp_postmeta`/`wp_posts`/`icl_translations`.
