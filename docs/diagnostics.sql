-- ===========================================================================
-- diagnostics.sql — reusable, read-only diagnostics for the Elementor/WPML
-- _elementor_data duplication issue. Safe to run on any environment.
-- Adjust the `wp_` prefix if the target install differs.
-- On Local (MySQL 8.4) run via the direct client + socket (see plugin CLAUDE notes).
-- ===========================================================================

-- 0. Environment: version / engine / prefix / collation
SELECT VERSION() AS server_version, @@version_comment AS server_comment;
SELECT table_name, engine, table_collation, table_rows,
       ROUND((data_length+index_length)/1048576,1) AS mb
FROM information_schema.tables
WHERE table_schema=DATABASE() AND table_name IN ('wp_postmeta','wp_posts','wp_options');

-- 1. Duplicate rows & size, per supported single-value Elementor key
SELECT meta_key,
       COUNT(*)                              AS total_rows,
       COUNT(DISTINCT post_id)               AS posts,
       COUNT(*)-COUNT(DISTINCT post_id)      AS excess_rows,
       ROUND(SUM(LENGTH(meta_value))/1048576,1) AS total_mb
FROM wp_postmeta
WHERE meta_key IN ('_elementor_data','_elementor_page_settings',
                   '_elementor_edit_mode','_elementor_template_type','_elementor_version')
GROUP BY meta_key ORDER BY excess_rows DESC;

-- 2. Redundant bytes = total size of rows that would be deleted (keeping MAX(meta_id))
SELECT COUNT(*) AS redundant_rows,
       ROUND(SUM(LENGTH(pm.meta_value))/1048576,1) AS redundant_mb
FROM wp_postmeta pm
JOIN (SELECT post_id, meta_key, MAX(meta_id) AS keep
      FROM wp_postmeta
      WHERE meta_key IN ('_elementor_data','_elementor_page_settings',
                         '_elementor_edit_mode','_elementor_template_type','_elementor_version')
      GROUP BY post_id, meta_key) k
  ON pm.post_id=k.post_id AND pm.meta_key=k.meta_key
WHERE pm.meta_key IN ('_elementor_data','_elementor_page_settings',
                      '_elementor_edit_mode','_elementor_template_type','_elementor_version')
  AND pm.meta_id<>k.keep;

-- 3. Verify each targeted key is genuinely single-value (max copies on any one post)
SELECT meta_key, MAX(c) AS max_copies_on_one_post, SUM(c>1) AS posts_with_dupes
FROM (SELECT post_id, meta_key, COUNT(*) c FROM wp_postmeta
      WHERE meta_key IN ('_elementor_data','_elementor_page_settings',
                         '_elementor_edit_mode','_elementor_template_type','_elementor_version')
      GROUP BY post_id, meta_key) t
GROUP BY meta_key;

-- 4. Identical vs divergent duplicates (per affected post) — retention risk
SELECT IF(distinct_vals=1,'IDENTICAL','DIVERGENT') AS kind,
       COUNT(*) AS posts, SUM(copies) AS total_rows
FROM (SELECT post_id, COUNT(*) AS copies,
             COUNT(DISTINCT MD5(meta_value)) AS distinct_vals
      FROM wp_postmeta WHERE meta_key='_elementor_data'
      GROUP BY post_id HAVING COUNT(*)>1) t
GROUP BY IF(distinct_vals=1,'IDENTICAL','DIVERGENT');

-- 5. Does the WP-served row (MIN meta_id) differ from newest (MAX)? — "keep" risk
SELECT SUM(min_md=max_md) AS min_equals_max, SUM(min_md<>max_md) AS min_differs_from_max
FROM (
  SELECT t.post_id,
    MD5((SELECT a.meta_value FROM wp_postmeta a
         WHERE a.post_id=t.post_id AND a.meta_key='_elementor_data' ORDER BY a.meta_id ASC  LIMIT 1)) AS min_md,
    MD5((SELECT b.meta_value FROM wp_postmeta b
         WHERE b.post_id=t.post_id AND b.meta_key='_elementor_data' ORDER BY b.meta_id DESC LIMIT 1)) AS max_md
  FROM (SELECT post_id FROM wp_postmeta WHERE meta_key='_elementor_data'
        GROUP BY post_id HAVING COUNT(*)>1) t
) x;

-- 6. JSON validity of every _elementor_data value (invalid = needs manual review)
SELECT SUM(JSON_VALID(meta_value)=0) AS invalid_json,
       SUM(JSON_VALID(meta_value)=1) AS valid_json, COUNT(*) AS checked
FROM wp_postmeta WHERE meta_key='_elementor_data';

-- 7. WPML classification of affected posts (original / translation / not-in-WPML)
SELECT CASE
         WHEN tr.source_language_code IS NOT NULL THEN 'translation'
         WHEN tr.language_code IS NOT NULL        THEN 'original'
         ELSE 'NOT IN WPML'
       END AS class,
       COUNT(DISTINCT d.post_id) AS posts
FROM (SELECT post_id FROM wp_postmeta WHERE meta_key='_elementor_data'
      GROUP BY post_id HAVING COUNT(*)>1) d
LEFT JOIN wp_icl_translations tr
  ON tr.element_id=d.post_id
 AND tr.element_type IN ('post_page','post_post','post_elementor_library','post_product')
GROUP BY class;

-- 8. Largest affected posts (display only — do NOT derive totals from this)
SELECT post_id, COUNT(*) AS copies,
       COUNT(DISTINCT MD5(meta_value)) AS distinct_vals,
       ROUND(SUM(LENGTH(meta_value))/1024,0) AS kb
FROM wp_postmeta WHERE meta_key='_elementor_data'
GROUP BY post_id HAVING copies>1 ORDER BY SUM(LENGTH(meta_value)) DESC LIMIT 20;
