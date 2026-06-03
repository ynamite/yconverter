# seo42 URL-Control → `url` Addon Migration — Design

- **Date:** 2026-06-03
- **Status:** Approved (design); pending implementation plan
- **Scope:** `yconverter` addon — migrate seo42's DB-dataset URL generation (R4 tables `rex_url_control_generate` / `rex_url_control_manager`) into the REDAXO 5 `url` addon (`rex_url_generator_profile` / `rex_url_generator_url`).

## 1. Context & problem

seo42 (R4) generated SEO URLs for database datasets via two tables:

- **`rex_url_control_generate`** — the **generation profiles** (the data actually used). Columns: `id`, `article_id`, `clang`, `url`, `table`, `table_parameters`, `createdate/createuser/updatedate/updateuser`.
- **`rex_url_control_manager`** — a separate "manual URL methods" feature (`method`, `method_parameters`, `status`, `url`). Empty in the reference site; a different concept from the generator.

The R5 `url` addon stores generation profiles in **`rex_url_generator_profile`** and the generated URLs in **`rex_url_generator_url`** (which the addon rebuilds from the profiles). This feature migrates the seo42 *generation profiles* into `url` profiles, with operator review for the parts that can't be derived mechanically.

### Old data format (verified against the reference site)

`rex_url_control_generate.table_parameters` is a **PHP-`serialize()`d** array keyed by *every* source table; the row's `table` column selects the active entry. Each table entry has 6 keys (`<table>_name`, `<table>_name_2`, `<table>_id`, `<table>_restriction_field`, `<table>_restriction_operator`, `<table>_restriction_value`). Reference row: `table = rex_vegafilm`, `article_id = 7`, `clang = 0/1/2`, active entry `{name: title, name_2: year, id: id, restriction_* empty}`.

### New data model (verified against `url` 2.3.0 + `Url\Cache::generateProfiles()`)

`rex_url_generator_profile`: `namespace`, `article_id`, `clang_id`, `ep_pre_save_called`, `table_name`, `table_parameters` (JSON), `relation_{1,2,3}_table_name/_parameters`, `createdate/createuser/updatedate/updateuser` (createdate/updatedate are `DATETIME`).

- **`table_name`** column stores `{dbId}_xxx_{realTable}` (separator `Url\Database::DATABASE_TABLE_SEPARATOR = '_xxx_'`; build via `Url\Database::merge($dbId, $table)`). The addon derives `dbid`/`name` from this column — they are **not** in the JSON.
- **`table_parameters`** JSON keys (per `Cache::generateProfiles`): `column_id`, `column_clang_id`, `column_segment_part_1/2/3`, `column_segment_part_2/3_separator`, `column_seo_title/description/image`, `column_sitemap_lastmod`, `relation_{1,2,3}_column/_position`, `restriction_{1,2,3}_column/_comparison_operator/_logical_operator/_value`, `append_structure_categories`, `append_user_paths`, `sitemap_add/_frequency/_priority`.

Reference target (hand-built by the operator): `namespace = movie`, `article_id = 2`, `clang_id = 1/2/3`, `table_name = 1_xxx_rex_yf_vegafilm`, `column_id = id`, `column_segment_part_1 = title`, `column_segment_part_2 = year` (sep `-`), plus operator-added restrictions/SEO/sitemap.

### Gap analysis (what is mechanically derivable)

| Field | Derivable? | Rule |
|---|---|---|
| one profile per old row | ✅ | 1:1 |
| `clang_id` | ✅ | old `clang` + 1 (R4 0-based → R5 1-based), with fallback + flag |
| `table_name` | ⚠️ | old `table` → `rex_yf_<base>` (YForm-migrated) or live R5 table; else flag |
| `column_id` | ✅ | `<table>_id` |
| `column_segment_part_1` / `_2` | ✅ | `<table>_name` / `<table>_name_2` (+ default separator `-`) |
| `restriction_1_*` | ✅ | from `<table>_restriction_field/operator/value` when set |
| `article_id` | ❌ flag | copied as-is; R4→R5 ids may differ |
| `namespace` | ❌ flag | default = table base; operator renames |
| SEO / sitemap / extra restrictions | ❌ | operator-added; left at defaults |

## 2. Decisions (from brainstorming)

| # | Decision | Choice |
|---|---|---|
| 1 | Output | **Preview/confirm wizard** in YConverter (new Step 5), reusing the Step-4 analyze→preview→apply pattern |
| 2 | Changed references | **Best-effort auto-fill + flag** — map table → `rex_yf_<base>`, copy `article_id`, flag both for review |
| 3 | Scope | **Generator profiles only** (`url_control_generate` → `url_generator_profile`); report any `url_control_manager` rows as manual follow-up |
| 4 | Structure | **Dedicated `YConverter\Url\` unit** (pure migrator + coupled importer + Step-5 UI), not a `Package` subclass |
| 5 | URL (re)generation | Use the addon's own recipe (`Cache::deleteProfiles` + `UrlManagerSql::deleteAll` + `Profile::getAll`→`buildUrls`) |

## 3. Architecture overview

```
yconverter_url_control_generate (staged)        rex_clang ids, resolved table
              │                                          │
              ▼                                          ▼
   UrlProfileImporter::detectProfiles()  ──►  ProfileMigrator::migrate()  ──►  UrlProfileMapping[]
              │                                                                      │
              │                                                          Step-5 preview (editable) ── operator edits
              ▼                                                                      │ (confirmed)
   UrlProfileImporter::apply()  ── INSERT rex_url_generator_profile ── then ──►  Url\Cache::deleteProfiles()
                                                                                 Url\UrlManagerSql::deleteAll()
                                                                                 foreach Url\Profile::getAll() → buildUrls()
```

The pure `ProfileMigrator` does all parsing/translation and is unit-tested; the importer handles DB I/O, table resolution, and the addon rebuild; Step 5 is a thin UI over them.

## 4. Components

### 4.1 `YConverter\Url\UrlProfileMapping` (value object)

One draft profile per old row:
`namespace`, `articleId` (int), `clangId` (int), `dbId` (int, =1), `tableName` (resolved R5 table, '' if unresolved), `tableParameters` (assoc array → the JSON), `createdate`, `updatedate`, `createuser`, `updateuser`, `sourceTable` (old name, display), `oldId`, `flags` (string[]).

### 4.2 `YConverter\Url\ProfileMigrator` (pure, unit-tested)

`migrate(array $oldRow, array $clangIds, ?string $resolvedTable): UrlProfileMapping`

1. `unserialize($oldRow['table_parameters'])`; `$t = $oldRow['table']`; `$active = $params[$t] ?? []`.
2. `column_id = $active[$t.'_id']`; `column_segment_part_1 = $active[$t.'_name']`; if `$active[$t.'_name_2']` non-empty → `column_segment_part_2 = name_2` + `column_segment_part_2_separator = '-'`.
3. If `$active[$t.'_restriction_field']` non-empty → `restriction_1_column/comparison_operator/value` from the restriction triple.
4. `clangId`: `oldClang+1` if `∈ $clangIds`; else `oldClang` if `∈ $clangIds`; else `min($clangIds)` + flag.
5. `tableName = $resolvedTable` (or '' + flag if null). `namespace` default = base of resolved/old table with `rex_`/`yf_` stripped + flag.
6. `articleId = (int) $oldRow['article_id']` + always flag "Artikel-ID prüfen".
7. timestamps: `date('Y-m-d H:i:s', (int) $oldRow['createdate'])` etc.; users copied.

Returns a `UrlProfileMapping`. No REDAXO calls (clang ids and resolved table are injected).

### 4.3 `YConverter\Url\UrlProfileImporter` (REDAXO-coupled)

- `isAvailable()` — `rex_addon::get('url')->isAvailable()` && `class_exists(Url\Profile)` etc.
- `detectProfiles()` — read staged `yconverter_url_control_generate` rows; also count `yconverter_url_control_manager` rows (→ follow-up notice). Returns the raw rows.
- `resolveTable($oldTable)` — strip `Config::getOutdatedTablePrefix()` → base; return `rex::getTable('yf_'.$base)` if it exists; else the live R5 table of that base name if it exists; else `null`.
- `analyze()` — per row: `resolveTable`, then `ProfileMigrator::migrate(...)` with `array_map('intval', array_keys(rex_clang::getAll()))`. Returns `UrlProfileMapping[]`.
- `apply(array $confirmed)` — per confirmed mapping (skip ones flagged removed):
  - delete prior profiles where `createuser = 'yconverter'` **and** `table_name = Url\Database::merge(dbId, table)` (re-runnable, preserves operator-created profiles);
  - INSERT `rex_url_generator_profile` row: `namespace`, `article_id`, `clang_id`, `ep_pre_save_called` (addon default `0`; confirm against the profile form at implementation), `table_name = Url\Database::merge($dbId, $tableName)`, `table_parameters = json_encode($params)`, relation columns empty, datetimes, `createuser/updateuser = 'yconverter'`.
  - after all inserts: `Url\Cache::deleteProfiles(); Url\UrlManagerSql::deleteAll(); foreach (Url\Profile::getAll() as $p) { $p->buildUrls(); }` (guarded by `class_exists`). Report profile + URL counts.

### 4.4 Step-5 wizard (`pages/convert.redaxo.php`)

- Shown when cloned **and** `UrlProfileImporter::isAvailable()` **and** staged `yconverter_url_control_generate` has rows. If the `url` addon is missing, a short note is shown instead.
- `func=url_analyze` → `analyze()` → `renderUrlPreview()` then `return` (mapping-mode, only preview + cancel link).
- `func=url_apply` → `buildUrlMappingsFromPost()` → `apply()` → messages + normal wizard.
- Preview: one editable row per profile — `namespace`, `article_id`, `clang_id`, source→target table, `column_id`, `segment_part_1`, `segment_part_2` + separator (`<select>` from `UrlManager::getSegmentPartSeparators()`), read-only restriction summary, flags column, and a skip/`__remove__` option. An info box lists any `url_control_manager` rows as manual follow-up.

### 4.5 Console (optional)

Extend `RunCommand --dry-run` to also print the derived URL profiles (read-only parity). Non-dry-run auto-apply optional and out of primary scope.

## 5. Edge cases

- **`url` addon not installed** → step hidden / noted; `analyze`/`apply` no-op with a message.
- **Unresolved source table** → `tableName` empty + flag; operator picks the table (or skips the profile).
- **clang not alignable** → fall back to `min(clangIds)` + flag.
- **Re-run** → only yconverter-created profiles for the same `table_name` are replaced; operator-created profiles are preserved.
- **`url_control_manager` rows present** → reported as manual follow-up; not migrated.
- **No segment column at all** (`<table>_name` empty) → still write the profile but flag "kein URL-Segment erkannt".

## 6. Files

- *New:* `lib/YConverter/Url/UrlProfileMapping.php`, `lib/YConverter/Url/ProfileMigrator.php`, `lib/YConverter/Url/UrlProfileImporter.php`.
- *Modified:* `pages/convert.redaxo.php` (Step 5 + `url_analyze`/`url_apply` + `renderUrlPreview`/`buildUrlMappingsFromPost`), `lang/de_de.lang`, `tests/run.php`, `README.md`, `CHANGELOG.md`, `CLAUDE.md`.
- *Optional:* `lib/console/RunCommand.php` (`--dry-run` URL-profile output).

## 7. Verification (no test harness; pure logic via `php tests/run.php`)

1. **Unit tests** — feed `ProfileMigrator` the real serialized blob (reference row): assert `column_id=id`, `column_segment_part_1=title`, `column_segment_part_2=year` (sep `-`), `clangId=1`, `namespace=vegafilm` (flag), `articleId=7` (flag), resolved `rex_yf_vegafilm` via a fake resolver, empty restriction. Plus: clang `+1`/fallback cases, unresolved-table flag, restriction extraction when set.
2. **Live read-only check** — `analyze` against the 3 real staging rows; compare to the hand-built profiles (columns/clang match; `namespace` and `article_id` are the flagged differences).
3. **Apply is not run against the live DB during development** (it would, correctly, replace yconverter-created profiles) — left to the operator on a disposable copy. The rebuild recipe is the same one the `url` addon's own profile page uses.

## 8. Assumptions

- The `url` addon's profile format (`table_name = {dbid}_xxx_{table}`, the `table_parameters` JSON keys, `DATETIME` timestamps) stays as verified against v2.3.0; `Url\Database::merge`, `Url\Cache::deleteProfiles`, `Url\UrlManagerSql::deleteAll`, `Url\Profile::getAll`, and `Url\Profile::buildUrls` are the public entry points used.
- Custom source tables referenced by seo42 profiles are migrated to YForm (`rex_yf_<base>`) before/with this step; otherwise the operator selects the target table in the preview.
- R4 clang ids are 0-based and the migrated R5 clang ids are 1-based (offset +1), editable per row.
