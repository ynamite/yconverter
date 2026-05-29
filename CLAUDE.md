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

`convert.redaxo.php` exposes a `run` action that executes compare → update → modify → transfer in one go.

### Two databases, confusingly numbered

`boot.php` merges the `db.5` connection from `config.yml` into REDAXO's DB config. Therefore:
- `rex_sql::factory('5')` / DB id `'5'` → the **OLD / source** database (the REDAXO 4 site). The slot is literally named `5` even though it holds the version-4 source.
- `rex_sql::factory()` (default id `1`) → the **current REDAXO 5** database.

`YConverter\Config` centralizes the conventions: source prefix + `core_version` (from `config.yml`), the `yconverter_` staging prefix, source DB id `'5'`, and the hardcoded R5 PHP/HTML value-field ids (`19`/`20`) used when folding old `php`/`html` slice columns into `REX_VALUE` slots.

### The `Package` abstraction (`lib/Package/`)

One subclass per migratable subsystem — `Core`, `Cronjob`, `Sprog`, `YForm` — extending abstract `Package`. To add coverage for another addon, add a subclass and wire it into the `switch` in `pages/convert.redaxo.php`. Each provides:

- `getName()` — the `rex_config` key / UI panel key.
- `getTables()` — **declarative** map `table => [ fromVersion => ['convertColumns'=>…, 'callbacks'=>…, …] ]`. Version keys may carry a comparator prefix (e.g. `'<4.0.0'`; default comparator is `>`), evaluated against `core_version`. `convertColumns` types: `timestamp` (unix int → `datetime`), `serialize` (PHP-serialized → JSON), `replace` (run the R4→R5 content rewriter).
- `updateTableStructure()` — **imperative** DDL, mostly the `rex_sql_table` fluent API (`ensureColumn`/`renameColumn`/`setName`/`alter`) plus raw `rex_sql` ALTERs. `Core` chains version-gated steps `to400()…to450()` then `to5xx()`.
- callback methods named in `getTables()` (e.g. `Core::callbackModifyArticleSlices`) for procedural data fixups.

`Modifier` also holds the large R4→R5 **content-rewrite regex tables** (`$replaces` / `$outdatedCode`): `$REX[...]` globals → `rex_*` calls, `OOArticle`/`OOMedia`/… → `rex_article`/`rex_media`/…, `REX_*` template variables, extension-point and addon renames (community→ycom, seo42, textile, etc.). Add new mappings here.

## Backend pages (`pages/`, registered in `package.yml`)

Page tree (admin-only): `yconverter` → `convert` → {`redaxo`, `xform`}, plus `settings`. `index.php` dispatches via `rex_be_controller::includeCurrentPageSubPath()`.

- **`convert.redaxo.php`** — the active driver. Renders one panel per package (`yconverter`/`core`/`cronjob`/`sprog`/`yform`), all on this single page, with CSRF-guarded step buttons that instantiate the right `Package` and call `YConverter`.
- **`settings.php`** — active settings form (source DB connection, `core_version`, table prefix); writes `data/addons/yconverter/config.yml`.

## Active vs. legacy code — important

Two generations of code coexist. **Only the namespaced pipeline above is active.** Do not edit the following expecting them to run; they use REDAXO 4 idioms (`global $REX`, `OOAddon`, `$I18N`) and are kept for reference:

- `lib/YConverter/_Converter.php` (class `YConverter\Converter`) and `lib/YConverter/YFormConverter.php`
- `pages/_convert.redaxo.php` and `pages/yform.inc.php`

The YForm migration in the active pipeline goes through **`lib/Package/YForm.php`**, not `YFormConverter.php`. The `xform` subpage declared in `package.yml` has no matching active page file (YForm is driven from `convert.redaxo.php`).

## Gotchas

- Package classes live in the `YConverter\Package` sub-namespace, distinct from the `YConverter\YConverter` orchestrator class — both resolve fine since `rex_autoload` is classmap-based, not path-based.
- `transferData()`/`Shuttle` is **destructive** on the target R5 DB (TRUNCATE). `cloneTables()` drops the `yconverter_*` staging tables on each run.
- `docs/reference/redaxo461.sql` and `redaxo521.sql` are reference schema dumps (R4.6.1 / R5.2.1) — the source of the structural diffs encoded in the Package DDL. `docs/` is gitignored.
