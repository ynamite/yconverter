# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

`yconverter` is a **REDAXO 5 backend AddOn that migrates a legacy REDAXO 4 (or 2.7+/3.x) site into a fresh REDAXO 5 installation** — schema and data. It is `2.0.0-dev` and, per the README, only conditionally usable; the complete, working REDAXO 4 implementation lives on the `redaxo4` branch (this `master` branch is the in-progress R5 rewrite).

## Build / test / run

There is **no build system, no Composer, and no tests** — it is a plain REDAXO AddOn (PHP). REDAXO's `rex_autoload` (a classmap scanner, not PSR-4) auto-indexes everything under `lib/`, so class names need not match file paths.

- "Running" means installing/activating the addon in a REDAXO 5 backend and driving the backend pages.
- After editing `package.yml` or adding/renaming `lib/` classes, **re-install (or re-activate) the addon, or clear the REDAXO cache** — otherwise the autoloader/page registry won't pick up the change.
- Linting/style: code targets the REDAXO core conventions (Yakamara), but no linter is wired up in this repo.

## Migration pipeline (the core mental model)

Migration runs as an ordered set of stages, orchestrated by `YConverter\YConverter` (`lib/YConverter/YConverter.php`), each operating on one `Package`. Completion of each step is recorded in `rex_config('yconverter', <packageName>, [...steps])` so the UI can grey out finished steps; `reset` clears it.

1. **`cloneTables()` → `Cloner`** — copies every `<prefix>*` table FROM the source DB INTO the current R5 DB, renaming the prefix to `yconverter_`. Drops/recreates the `yconverter_*` staging tables each run. Package-independent (clones the whole old DB).
2. **`updateTables()` → `Updater` → `Package::updateTableStructure()`** — brings the staged `yconverter_*` table **structure** up to what R5 expects (per-package DDL).
3. **`modifyTables()` → `Modifier`** — rewrites cell **contents** per `Package::getTables()` declarations, then runs that package's callbacks ordered by level (`EARLY`/`NORMAL`/`LATE`).
4. **`compareTables()` → `Compare`** — warns about tables/columns present in the staged data but missing from the live R5 tables.
5. **`transferData()` → `Shuttle`** — **TRUNCATEs the real R5 tables** and copies the intersecting columns from `yconverter_*` into them, then clears the developer-addon export dirs and the cache.

`convert.redaxo.php` exposes a `run` action that executes update → modify → compare → transfer in one go.

### Two databases

`boot.php` merges the `db.2` connection from the addon's own `config.yml` into REDAXO's DB config (in the backend and under CLI). Therefore:
- `rex_sql::factory('2')` / DB id `'2'` → the **OLD / source** database (the REDAXO 4 site). Connection `2` is REDAXO 5's conventional "second connection".
- `rex_sql::factory()` (default id `1`) → the **current REDAXO 5** database.

The source DB can be the live R4 site DB **or** a database into which a `mysqldump` of it was restored — same mechanism.

`YConverter\Config` centralizes the conventions: source prefix + `core_version` (from `config.yml`), the `yconverter_` staging prefix, source DB id `'2'`, the optional `media_source_path`, and the hardcoded R5 PHP/HTML value-field ids (`19`/`20`) used when folding old `php`/`html` slice columns into `REX_VALUE` slots. `Config::isValid()` / `getValidationErrors()` gate the pipeline before any destructive step.

### The `Package` abstraction (`lib/Package/`)

One subclass per migratable subsystem — `Core`, `Cronjob`, `Sprog`, `YForm` — extending abstract `Package`. To add coverage for another addon, add a subclass and wire it into the `switch` in `pages/convert.redaxo.php`. Each provides:

- `getName()` — the `rex_config` key / UI panel key.
- `getTables()` — **declarative** map `table => [ fromVersion => ['convertColumns'=>…, 'callbacks'=>…, …] ]`. Version keys may carry a comparator prefix (e.g. `'<4.0.0'`; default comparator is `>`), evaluated against `core_version`. `convertColumns` types: `timestamp` (unix int → `datetime`), `serialize` (PHP-serialized → JSON), `replace` (run the R4→R5 content rewriter).
- `updateTableStructure()` — **imperative** DDL, mostly the `rex_sql_table` fluent API (`ensureColumn`/`renameColumn`/`setName`/`alter`) plus raw `rex_sql` ALTERs. `Core` chains version-gated steps `to400()…to450()` then `to5xx()`.
- callback methods named in `getTables()` (e.g. `Core::callbackModifyArticleSlices`) for procedural data fixups.

`Modifier` also holds the large R4→R5 **content-rewrite regex tables** (`$replaces` / `$outdatedCode`): `$REX[...]` globals → `rex_*` calls, `OOArticle`/`OOMedia`/… → `rex_article`/`rex_media`/…, `REX_*` template variables, extension-point and addon renames (community→ycom, seo42, textile, etc.). Add new mappings here.

## Backend pages (`pages/`, registered in `package.yml`)

Page tree (admin-only): `yconverter` → `convert` → `redaxo`, plus `settings`. `index.php` dispatches via `rex_be_controller::includeCurrentPageSubPath()`.

- **`convert.redaxo.php`** — the active driver. Renders one panel per package (`yconverter`/`core`/`cronjob`/`sprog`/`yform`), all on this single page, with CSRF-guarded step buttons that instantiate the right `Package` and call `YConverter`.
- **`settings.php`** — active settings form (source DB connection, `core_version`, table prefix); writes `data/addons/yconverter/config.yml`.

## Active vs. legacy code — important

Two generations of code coexist. **Only the namespaced pipeline above is active.** Do not edit the following expecting them to run; they use REDAXO 4 idioms (`global $REX`, `OOAddon`, `$I18N`) and are kept for reference:

- `lib/YConverter/_Converter.php` (class `YConverter\Converter`) and `lib/YConverter/YFormConverter.php`
- `pages/_convert.redaxo.php` and `pages/yform.inc.php`

The YForm migration in the active pipeline goes through **`lib/Package/YForm.php`**, not `YFormConverter.php` (YForm is driven from `convert.redaxo.php`). The dead `xform` subpage was removed from `package.yml`.

### Schema detection (custom-table → YForm import)

Column → YForm-field mapping lives in **`lib/YConverter/Schema/`** and is intentionally decoupled from YForm:

- **`SchemaDetector`** — pure engine: an ordered, declarative rule set (`rules()`) matches on column name + MySQL type + lazily sampled values; first match wins, with the former `mapType()` logic as the LOW-confidence `typeFallback()`. An i18n pass groups `prefix_<n>` columns (suffixes resolved to `rex_clang` ids, direct or via a single offset for R4 0-based → R5 1-based) into one `lang_text`/`lang_textarea`/`lang_media` field. A final, optional AI pass refines only LOW-confidence fields. **Add new rules in `rules()`.** The detector takes no REDAXO calls — clang ids, addon availability, existing-field types, and the value sampler are injected.
- **`FieldMapping`** — the per-field result (name, label, typeName, dbType, params, confidence, reason, source, members).
- **`ValueSampler`** — lazy `SELECT DISTINCT … LIMIT 51` for value-aware rules.
- **`LangDataMerger`** — the i18n collapse: pure `encodeRow()` reproduces the `yform_lang_fields` JSON byte-for-byte (`[{"clang_id","value"},…]`, `JSON_UNESCAPED_UNICODE`, empties omitted); `merge()` performs the in-place, idempotent, safe-ordered data transform (add JSON column → populate → drop member columns). Collapse only happens when the **`yform_lang_fields`** addon is installed.
- **`Ai/*`** — optional, gap-fill-only `OpenAiProvider`/`AnthropicProvider` (one `rex_socket` call, no Composer dep) + pure `AiResponseParser`; configured in **Settings** (provider/key/model/send-samples). Never required; `Config::isValid()` ignores AI.

`YFormImporter` consumes confirmed `FieldMapping[]`: `analyze()` (detect, write nothing), `import()` (new staging table → YForm table), `refreshFields()` (re-detect an already-imported `rex_yf_*` table, replacing only its `yform_field` rows). Step 4 of `convert.redaxo.php` is an **analyze → preview → apply** wizard with two lists: new custom tables and already-imported YForm tables (retroactive re-detection via `detectExistingYFormTables()`). The console `yconverter:run --dry-run` prints the detected mappings for both lists and writes nothing (read-only — it short-circuits before clone/migrate).

The pure detection logic is unit-tested with a zero-dependency runner: **`php tests/run.php`** (no Composer/PHPUnit). REDAXO-coupled glue is verified manually.

### seo42 → url migration

`lib/YConverter/Url/` migrates seo42 URL-control profiles into the `url` addon: `ProfileMigrator` (pure: `unserialize` the old `table_parameters`, map columns + `clang` +1, flag review items), `UrlProfileImporter` (resolve old table → `rex_yf_*`, write `rex_url_generator_profile`, rebuild via `Url\Cache`/`Url\UrlManagerSql`/`Url\Profile`), and Step 5 of `convert.redaxo.php`. Addon-conditional (`rex_addon::get('url')`); only `url_control_generate` → `url_generator_profile` is migrated, `url_control_manager` is report-only.

## Gotchas

- Package classes live in the `YConverter\Package` sub-namespace, distinct from the `YConverter\YConverter` orchestrator class — both resolve fine since `rex_autoload` is classmap-based, not path-based.
- `transferData()`/`Shuttle` is **destructive** on the target R5 DB (TRUNCATE). `cloneTables()` drops the `yconverter_*` staging tables on each run.
- `docs/reference/redaxo461.sql` and `redaxo521.sql` are reference schema dumps (R4.6.1 / R5.2.1) — the source of the structural diffs encoded in the Package DDL. `docs/` is gitignored.
