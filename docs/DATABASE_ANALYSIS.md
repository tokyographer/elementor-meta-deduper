# DATABASE_ANALYSIS.md

Direct analysis of the active Local database, run against a faithful pre-cleanup fixture.

- **Fixture:** `emdd_fixture` — a disposable schema loaded from a pre-cleanup snapshot of
  `wp_postmeta` / `wp_posts` / `wp_options`, plus `wp_icl_translations` / `wp_icl_languages` for the WPML
  joins. This is a real-world case study of one affected site; the figures below are illustrative of the
  bug's shape, not a spec. Reusable queries: `diagnostics.sql`.

## Environment (confirmed from the server)

| Property | Value |
|---|---|
| Server | **MySQL 8.4.0** (Community Server — GPL). *Not* MariaDB. |
| Table prefix | `wp_` |
| Engine (all hot tables) | **InnoDB** |
| Collation | `utf8mb4_unicode_520_ci` |
| WPML | active, 5 languages, `wp_icl_translations` = 2,802 rows |

## Duplication scope (measured, not estimated)

| meta_key | total rows | posts | excess rows | size |
|---|--:|--:|--:|--:|
| **`_elementor_data`** | 3,250 | 1,011 | **2,239** | 343.4 MB |
| `_elementor_edit_mode` | 1,027 | 1,027 | 0 | 0.0 MB |
| `_elementor_page_settings` | 873 | 873 | 0 | 0.3 MB |
| `_elementor_template_type` | 1,062 | 1,062 | 0 | 0.0 MB |
| `_elementor_version` | 1,061 | 1,061 | 0 | 0.0 MB |

- **Redundant payload (rows that would be deleted, keeping newest):** **2,239 rows / 245.0 MB.**
- **Only `_elementor_data` is duplicated.** The other four targeted keys are already strictly
  single-value (0 excess), which both confirms they are safe dedup targets *and* that they contribute
  nothing to the current bloat.

### Single-value verification

Max copies of each key on any single post: `_elementor_data` = **152**; all four others = **1**. So the
five keys are genuinely single-value keys (they *should* have one row per post); only `_elementor_data`
has been violated by the sync bug. Dedup is semantically valid for all five.

## Retention risk — the important finding

Of the **83 posts** with duplicate `_elementor_data`:

| | posts | rows |
|---|--:|--:|
| **IDENTICAL** copies (all bytes equal) | **42** | 1,123 |
| **DIVERGENT** copies (2+ distinct values) | **41** | 1,199 |

- **MIN(meta_id) value vs MAX(meta_id) value:** equal for **42** posts, **different for 41**.
- **JSON validity:** **0 invalid**, 3,250 valid — every value parses as JSON.

**Consequence:** for the 42 identical-copy posts, dedup is lossless regardless of which row you keep.
For the **41 divergent posts, "keep newest" and "keep oldest" produce different rendered content** —
neither is a guaranteed no-op. The current plugin's implied "oldest = zero visual change" is only
defensible in the narrow sense that WordPress serves `MIN(meta_id)` for a single-value key, so keeping
`MIN` preserves *what is currently rendered*. But "newest" will visibly change ~half the affected pages.
This is why the hardened design must classify identical vs divergent and treat divergent posts under an
explicit policy (or flag for review), not silently pick one.

## Translation classification

All **83** affected posts are **WPML translations** — **0 originals, 0 outside WPML**. This matches the
known root cause (sync-on-save appends to translations). It does **not** license the code to *assume*
every duplicate is a translation; the scanner must classify from `wp_icl_translations` and report the
breakdown, because a different site (or a future regression) could duplicate originals too.

## Largest affected posts (display sample)

| post_id | copies | distinct values | size |
|--:|--:|--:|--:|
| 11743 | 152 | 32 | 33.6 MB |
| 6747 | 150 | **1 (identical)** | 21.8 MB |
| 7119 | 147 | 1 (identical) | 21.4 MB |
| 6503 | 110 | 1 (identical) | 16.0 MB |
| 11744 | 73 | 32 | 15.3 MB |
| 10440 | 120 | 25 | 13.6 MB |
| 11745 | 61 | 32 | 12.6 MB |
| 7697 | 97 | 6 | 10.7 MB |

Note the mix: 6747/7119/6503 are pure identical bloat (safe to auto-clean), while 11743/11744/11745 are
divergent (need policy/review). A one-size dedup would silently discard real layout variations on the
divergent set.

## Implications for the plugin (feeds the hardening plan)

1. Totals must be computed over the full set, never from the `LIMIT`ed display query.
2. Retention must branch on identical vs divergent; export a manifest before deleting.
3. Translation classification must come from WPML tables and be reported, not assumed.
4. Optimizer must detect MySQL vs MariaDB (here: MySQL 8.4 → needs the `wp_posts` `sql_mode` workaround);
   MariaDB would not hit that specific error.
5. All JSON currently valid — but the executor must still tolerate invalid/oversized values (flag, skip).
