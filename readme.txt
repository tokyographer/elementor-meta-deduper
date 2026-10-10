=== Elementor Meta Deduper ===
Contributors: (your-wporg-username)
Tags: elementor, wpml, database, cleanup, postmeta, optimization
Requires at least: 6.0
Requires PHP: 8.0
Stable tag: 2.1.0
License: GPLv2 or later

Detects and safely removes duplicate single-value Elementor postmeta rows caused by the
WPML x Elementor translation-sync bug, and warns you when they come back.

== Description ==

On WooCommerce + Elementor + WPML sites, a translation-sync bug can insert a new
`_elementor_data` row on every save of a translated page instead of updating the existing
one. Pages accumulate dozens or hundreds of copies of their layout JSON, bloating
`wp_postmeta` into the hundreds of MB and causing PHP "memory exhausted" fatal errors.

This plugin:

* **Scans** (read-only) for duplicate single-value Elementor meta and reports the worst pages.
* **Cleans** safely — keeps exactly one row per page (newest or oldest, your choice), wrapped
  in a transaction and logged. Optionally also removes revisions, orphaned meta, and transients.
* **Reclaims disk** with a table rebuild (includes the MySQL 8.4 `wp_posts` workaround).
* **Guards weekly** via WP-Cron: emails an admin if duplicates reappear — your signal that the
  underlying WPML/Elementor bug is still active.

Safety: only touches the whitelisted single-value Elementor keys (`_elementor_data`,
`_elementor_page_settings`, `_elementor_edit_mode`, `_elementor_template_type`,
`_elementor_version`). Destructive actions require `manage_options` and a nonce.

**Important:** this fixes accumulated data, it does not fix the root cause. Update SitePress +
the WPML Page Builders glue module + Elementor to stop the regrowth.

== Usage ==

Admin: Tools → Elementor Meta Deduper. Scan is automatic on load; tick the cleanup options and
run. Always back up first, then regenerate Elementor CSS and clear caches afterward.

WP-CLI (recommended for anything non-trivial; browser cleanups are capped at 500 rows):

  wp elementor-dedup scan                          # accurate totals + classification
  wp elementor-dedup plan   [--include-divergent]  # dry deletion plan
  wp elementor-dedup export --export=/path.json    # write manifest
  wp elementor-dedup clean  --dry-run              # plan + manifest, no deletion
  wp elementor-dedup clean  [--keep=newest|oldest] [--batch-size=25] [--max-delete=3000] \
                            [--post-id=123] [--translations-only] [--include-divergent] \
                            [--resume] [--yes] [--format=table|json]
  wp elementor-dedup revisions [--dry-run]         # delete post revisions (bloat source)
  wp elementor-dedup verify                        # residual check
  wp elementor-dedup optimize                      # evidence-based table rebuild (never cron)

Exit codes: 0 ok · 1 usage · 2 failed+rolledback · 3 partial/capped · 4 residue · 5 needs-review.

== Changelog ==

= 2.1.0 =
* Fixed: cleanup plan now covers all five Elementor meta keys, not only _elementor_data, so "rows remain" always converges to zero.
* Fixed: JSON validity only enforced for _elementor_data; plain-string keys no longer flagged for review.
* Fixed: verifier reports excess rows (same meaning as the scanner).
* Fixed: admin cleanup no longer leaves an emdd_progress_* option behind per run.
* Added: revision cleanup (wp elementor-dedup revisions, admin action); autosaves are skipped.

= 2.0.0 =
* Hardened for production. Component architecture (see docs/ARCHITECTURE_PROPOSAL.md).
* Fail-loud DB layer (execute_or_fail + DatabaseQueryException) — partial transactions now roll back
  instead of silently committing.
* Accurate reporting: totals from COUNT(*), never from a LIMIT-ed display query.
* Identical-vs-divergent retention policy; divergent posts require an explicit policy or are flagged
  for review; pre-delete JSON manifest export.
* Batched, resumable, idempotent executor with max-delete cap and run-lock.
* WP-CLI-first workflow with exit codes; admin rejects large browser cleanups.
* Translation-aware scanning via WPML tables (classified, not assumed).
* Environment-aware optimizer (MySQL/MariaDB detect; evidence-based reclaim; never cron).
* Structured audit log; notify-only recurrence monitor with thresholds + suppression (Action Scheduler
  when available).
* Integration test suite incl. injected-failure rollback (tests/run-tests.sh).

= 1.0.0 =
* Initial release: scan, cleanup, optimize, weekly guard, WP-CLI commands.
