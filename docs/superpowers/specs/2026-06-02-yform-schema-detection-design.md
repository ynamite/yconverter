# YForm Schema Detection — Design

- **Date:** 2026-06-02
- **Status:** Approved (design); pending implementation plan
- **Scope:** `yconverter` addon (REDAXO 5), Step-4 custom-table → YForm import + retroactive re-detection on already-imported YForm tables

## 1. Context & problem

The active custom-table → YForm path is `YConverter\YFormImporter` (`lib/YConverter/YFormImporter.php`). It detects cloned staging tables that are neither core nor a known addon (`detectCustomTables()`), rebuilds each as `rex_yf_<name>`, and registers one `yform_table` row plus one `yform_field` row per column.

Field types are inferred **purely from the MySQL column type** in `mapType()` (e.g. `tinyint(1)`→`checkbox`, `int`→`integer`, `text`→`textarea`). There is **no awareness of the column name, its sampled values, or cross-column patterns**. The operator must fix nearly every field type by hand in YForm afterward.

This design adds **smart schema detection**: a reusable, name- + type- + value-aware engine that proposes good YForm field types (including the `yform_lang_fields` multilingual types), an operator **preview/confirm** step, an **optional AI** assist for ambiguous columns, and the ability to **re-run detection on tables that were already imported**.

## 2. Goals / non-goals

**Goals**

1. Map columns to the right YForm field type using column name + MySQL type + sampled values, covering at least:
   - `status`/`online`/`active` (tinyint, values ⊆ {0,1}) → `choice` with `offline=0,online=1`
   - `year` → `number` (default; reason notes year-only-date alternative)
   - `url`/`website`/`link`/`homepage` → `url`
   - `*file*`/`image`/`photo`/`pdf`/`attachment` → `be_media` (`multiple` when plural/`text`-typed)
   - `prefix_<n>` i18n groups → `lang_text` / `lang_textarea` / `lang_media`
2. **Preview & confirm:** show every column → proposed field (type + params + confidence + reason); operator edits any mapping before it is written.
3. **Heuristics-first, AI optional:** the engine is fully functional with no API key. AI only refines columns left at LOW confidence, only when configured, and can never override or worsen a confident heuristic result.
4. **Retroactive re-detection:** run detection on already-imported `rex_yf_*` tables and refresh their `yform_field` definitions (and perform the i18n data transform) without touching the data table or the `yform_table` row.
5. Detection engine is a **standalone, reusable class** decoupled from YForm, so the deferred "analyze any table" tool is later a thin UI over it.

**Non-goals (this iteration)**

- A standalone "analyze any arbitrary table" backend page (deferred; re-detection already lists *all* `rex_yform_table` entries, delivering most of it).
- Relation detection (`*_id` → `be_manager_relation`) — left as `integer`; out of scope.
- Chunked/async detection (detection is cheap; Step 4 stays a normal POST).
- Changing the clone/migrate/media pipeline or the AJAX runner (`lib/api/run.php`).

## 3. Decisions (from brainstorming)

| # | Decision | Choice |
|---|---|---|
| 1 | AI role | **Heuristics-first, AI optional** — AI refines only LOW-confidence columns, only when a key is set |
| 2 | Apply model | **Preview table, confirm/override** before anything is written |
| 3 | Entry points | **Step-4 only**, engine built reusable; "any table" deferred |
| 4 | i18n storage | `yform_lang_fields` lang fields are **JSON-based** → an i18n **data transform** is required |
| 5 | AI defaults | provider `none`; "send sample values to AI" **on** but toggleable |

## 4. Architecture overview

```
                 ┌─────────────────────────────────────────────┐
   columns +     │              SchemaDetector                  │
   sampler  ───▶ │  1. column pass (ordered declarative rules)  │ ──▶ FieldMapping[]
   (a table)     │  2. i18n grouping pass (rex_clang-aware)     │
                 │  3. AI pass (LOW-confidence only, optional)  │
                 └─────────────────────────────────────────────┘
                            │                         ▲
                            ▼                         │
                   preview/confirm UI  ── edits ──────┘
                            │ (confirmed FieldMapping[])
                            ▼
                 ┌─────────────────────────────────────────────┐
                 │                YFormImporter                 │
                 │  import(base, mappings)     [new table]      │
                 │  refreshFields(table, mappings) [existing]   │
                 │      └─ LangDataMerger (i18n transform)      │
                 └─────────────────────────────────────────────┘
```

The detector is **source-agnostic**: it is handed a column list plus a `ValueSampler` bound to a specific table. The caller decides whether that table is a staging `yconverter_*` table (fresh import) or a live `rex_yf_*` table (retroactive re-detect). Same rules, same passes.

## 5. Components

### 5.1 `YConverter\Schema\FieldMapping` (value object)

One per resulting YForm field.

| property | purpose |
|---|---|
| `name`, `label` | field name (column / i18n prefix) + prettified label |
| `typeId`, `typeName` | always `value`; the YForm type (`text`, `textarea`, `choice`, `be_media`, `url`, `datetime`, `date`, `time`, `integer`, `number`, `lang_text`, `lang_textarea`, `lang_media`) |
| `dbType` | original MySQL type, preserved |
| `params` | map of `yform_field` column → value (e.g. `choices => 'translate:offline=0,online=1'`, `multiple => 1`) — mirrors the existing `convertValues` shape in `YForm.php` |
| `confidence` | `HIGH` / `MEDIUM` / `LOW` (class constants) |
| `reason` | human text for preview + report |
| `source` | `rule:<id>` / `type` / `ai` / `manual` |
| `members` | i18n only: collapsed source columns + their suffix→clang-id map |

### 5.2 `YConverter\Schema\SchemaDetector` (engine)

`detect(string $tableName, array $columns, ValueSampler $sampler): FieldMapping[]`, three passes:

**Pass 1 — column pass.** An ordered, declarative rule array; first match wins. Each rule:

```php
[
  'id'         => 'status-choice',
  'name'       => '/^(status|online|active|published)$/i', // optional name regex
  'dbType'     => '/^tinyint/',                             // optional MySQL-type regex
  'values'     => fn(array $distinct) => /* ⊆ {0,1} */,     // optional sampled-value test
  'field'      => 'choice',
  'params'     => ['choices' => 'translate:offline=0,online=1'],
  'confidence' => FieldMapping::HIGH,
  'reason'     => 'Spaltenname status + Werte 0/1',
]
```

All present conditions must hold; rules ordered specific → general. `values` predicates pull from the `ValueSampler` lazily (only when a rule needs them). The **existing `mapType()` logic becomes the lowest-priority fallback rule**, so unmatched columns behave exactly as today (no regression).

**Pass 2 — i18n grouping.** See §6.

**Pass 3 — AI (optional).** See §7. Operates only on fields still at `LOW` confidence.

#### Shipped rule set

| id | matches | → field | params / notes | confidence |
|---|---|---|---|---|
| `status-choice` | name `status\|online\|active\|published` + `tinyint` + values ⊆ {0,1} | `choice` | `choices: translate:offline=0,online=1` | HIGH |
| `year-number` | name `year\|jahr\|*_year` | `number` | reason notes year-only-date alternative | MEDIUM |
| `url` | name `url\|website\|link\|homepage\|href` + char type | `url` | | HIGH |
| `media` | name contains `file\|image\|photo\|pdf\|attachment\|media\|bild\|datei` + char type | `be_media` | `multiple: 1` when name plural or column is `text`/`longtext` | MEDIUM/HIGH |
| `email` | name `email\|mail\|e_mail` + char type | `text` | reason: "add email validator" | MEDIUM |
| `price-number` | name `price\|amount\|cost\|betrag\|preis` + `decimal/float/double` | `number` | precision from dbType | MEDIUM |
| `longtext-textarea` | name `description\|body\|content\|notes\|comment\|text` or text-typed | `textarea` | (also baseline) | MEDIUM |
| `type-fallback` | (always) | per existing `mapType()` | unchanged behaviour | LOW |

The set is editable in one array, matching house style (`AddonMap::replaces()`, `Package::getTables()`).

### 5.3 `YConverter\Schema\ValueSampler`

`distinct(string $column, int $limit = 51): array` — `SELECT DISTINCT \`col\` ... WHERE col IS NOT NULL LIMIT :limit` against the bound table; `LIMIT 51` lets a rule cheaply tell "small fixed set" from "free text". Reads the **staging** table for imports and the **live** `rex_yf_*` table for re-detection (no cross-DB hop — both live in the R5 DB). Sampling is lazy.

### 5.4 `YConverter\Schema\LangDataMerger` (i18n data transform)

Owns the destructive i18n collapse (§6). Addon-aware (`yform_lang_fields`), idempotent, and orders work safe-first (populate + verify before dropping source columns).

### 5.5 AI layer — `YConverter\Schema\Ai\*`

`AiFieldProvider` interface:

```php
proposeFields(array $columns, array $allowedTypes, array $clangs): array
// returns name => ['typeName' => ..., 'params' => [...], 'reason' => ...]
```

`OpenAiProvider` and `AnthropicProvider`, each one HTTP call via **`rex_socket`** (no Composer dep). The prompt provides the LOW-confidence columns (name, MySQL type, a few sample values), the catalogue of allowed YForm types with one-line meanings, and the clang list; the model must return strict JSON. Anything unparseable or naming a type outside the catalogue is discarded — the heuristic result stands. Model IDs are current-at-implementation defaults, overridable in settings.

### 5.6 `YFormImporter` changes

| operation | data table | `yform_table` row | `yform_field` rows |
|---|---|---|---|
| `import(base, mappings)` | build (`CREATE LIKE` + `INSERT SELECT` + primary id) | register | write from mappings |
| `refreshFields(tableName, mappings)` | **untouched** (except i18n merge) | **untouched** | replace (`DELETE … WHERE table_name` + re-insert) |

- `convertTable()` no longer calls `mapType()` per column; it consumes confirmed `FieldMapping[]`.
- `registerYFormFields()` writes each mapping's `type_id`/`type_name`/`name`/`label`/`db_type` + every `params` entry into its real `yform_field` column, reusing the fill-all-columns `insertRow()`.
- New `detectExistingYFormTables()` lists every `rex_yform_table.table_name` for the re-detect candidate list.
- `import()` and `refreshFields()` both invoke `LangDataMerger` for confirmed `lang_*` mappings.

### 5.7 UI — Step 4 wizard (`pages/convert.redaxo.php`)

Two-stage, replacing today's one-shot `yformimport`:

- **Stage A — analyze** (`func=yform_analyze`): two candidate lists —
  - *New custom tables* (staging `yconverter_*`, today's `detectCustomTables()`) → apply = **import**
  - *Existing YForm tables* (`detectExistingYFormTables()`) → apply = **refresh fields**

  Operator ticks tables; on submit, `SchemaDetector` runs per table and renders a **preview form**, one section per table, one row per resulting field:

  | Column(s) | YForm type (editable `<select>`) | Key params (editable) | Confidence | Reason |
  |---|---|---|---|---|

  Each table section is tagged **NEW (import)** vs **REFRESH (replace fields)**. REFRESH sections warn that existing field customizations for that table will be replaced. i18n groups show their members and an **editable suffix→clang-id map**. Nothing is written yet.

- **Stage B — apply** (`func=yform_import`): the posted, possibly-edited mappings are handed to `YFormImporter` (no re-detection — what was shown is what is written). `import()` or `refreshFields()` per table. Same success messaging as today.

Stage A is synchronous; with AI enabled it adds one HTTP call per table (spinner). The AJAX runner is not involved.

### 5.8 Console — `lib/console/RunCommand.php`

Non-interactive, so **auto-apply**: run detector → write proposed mappings directly, for both new and existing tables. Add `--dry-run` to print the detected mapping table and write nothing (also the cheap manual-verification aid).

### 5.9 Config / settings

- `Config` getters: `getAiProvider()`, `getAiApiKey()`, `getAiModel()`, `getAiSendSamples()`. `isValid()` **unchanged** — AI is never required.
- `pages/settings.php` adds: AI provider select (`none`/OpenAI/Anthropic), API-key (password input, masked like the DB password), model (optional text), "send sample values to AI" checkbox (default on). Written to the same gitignored `data/addons/yconverter/config.yml`.

## 6. i18n collapse — detail

**Storage format** (authoritative, from `yform_lang_fields` `rex_yform_value_lang_text::formatValueForSave()`): a JSON **list** of `{clang_id, value}` objects, encoded with `JSON_UNESCAPED_UNICODE`, with **empty/whitespace values omitted**, `clang_id` cast to `(int)`:

```json
[{"clang_id":1,"value":"Titel DE"},{"clang_id":2,"value":"Title EN"}]
```

The storage column type is `text` (`db_type => ['text']`).

**Detection.** Scan names for `^(.+)_(\d+)$`; a group qualifies when it has ≥2 members whose suffix set maps onto live `rex_clang` ids and the members share a base type. The base field type is `lang_text` / `lang_textarea` / `lang_media` chosen from the members' detected base type.

**Suffix→clang-id map.** R4 was 0-based, R5 is 1-based (cf. the old `callbackModifyLangTextareaInTables` `+1` shift). The detector defaults to a direct match when suffixes ⊆ live clang ids, otherwise proposes an offset. The **preview exposes this map for the operator to correct** before the merge.

**Transform** (`LangDataMerger`, runs at apply for both `import()` and `refreshFields()`):

1. Add a `text` column named after the prefix (e.g. `title`). If a column of that exact name already exists, skip + warn (collision).
2. For each row, build the `{clang_id,value}` list from the member columns (mapping each suffix via the confirmed map, **skipping empty/whitespace values** to match YForm byte-for-byte) and `json_encode(..., JSON_UNESCAPED_UNICODE)` into the new column.
3. **Verify** the populate, **then** `DROP COLUMN` the members. (`DROP COLUMN` auto-commits and is not rollback-able in MySQL, so the drop happens only after a verified populate; on failure both old and new columns remain and the step is re-runnable.)
4. Register one `lang_*` field named after the prefix.

**Addon-conditional.** If `rex_addon::get('yform_lang_fields')->isAvailable()` is false: **no collapse.** Each member is mapped individually (`text`/`textarea`/`be_media`) with a language-tagged label (e.g. `Title [de]`), and the preview states why. Feature degrades cleanly for other YConverter users.

**Idempotency.** Detection on a live YForm table reads the existing `yform_field` definitions as a prior: a column already registered as `lang_*` is preserved and **not** re-transformed; collapse only fires while the `_<n>` member columns still physically exist.

**Round-trip check.** Generated JSON for a sample row is validated by feeding it through `LangHelper::normalizeLanguageData()` and confirming it yields the expected per-language values.

## 7. AI assist — detail

- Runs **only** over fields still at `LOW` confidence after passes 1–2, and **only** when a provider + key are configured.
- HIGH/MEDIUM matches are never sent and never overridden.
- Payload: column name, MySQL type, and (if "send sample values" is on) a few sampled values; plus allowed-type catalogue and clang list. With the toggle off, only names + types leave the server.
- Failure modes (no key, network error, bad JSON, unknown type) all degrade silently to the heuristic result.
- **Privacy:** never bulk row data; only schema + a handful of samples; only when the operator entered a key.

## 8. Edge cases

- **No regression:** unmatched columns fall through to the `mapType()` baseline rule.
- **i18n column-name collision** (a real column already equals the prefix) → skip collapse for that group, warn.
- **Re-detect overwrites field customizations** → surfaced by the REFRESH tag + warning; the preview is the consent point.
- **`id` column** → still skipped (YForm owns the primary id).
- **Large tables on apply** → i18n merge rewrites rows; acceptable for one-time operator-triggered runs; chunking is out of scope.
- **`choice` value semantics** (`offline=0` may not hold for every legacy site) → editable in the preview.

## 9. Files

**New** (`lib/YConverter/Schema/`): `FieldMapping.php`, `SchemaDetector.php`, `ValueSampler.php`, `LangDataMerger.php`, `Ai/AiFieldProvider.php`, `Ai/OpenAiProvider.php`, `Ai/AnthropicProvider.php`.

**Modified:** `lib/YConverter/YFormImporter.php`, `lib/YConverter/Config.php`, `pages/convert.redaxo.php`, `pages/settings.php`, `lib/console/RunCommand.php`, `lang/de_de.lang` (+ `en_gb.lang` if present).

(`rex_autoload` is classmap-based, so the `YConverter\Schema\*` sub-namespace needs no path-matching; re-install/re-activate or clear cache after adding classes.)

## 10. Verification (no test harness in repo)

Use the `~/Herd/vegafilm` install (has `yform_lang_fields` + already-imported `rex_yf_*` tables):

1. `RunCommand --dry-run` prints proposed mappings for a custom table — eyeball the five example behaviors.
2. Re-detect an already-imported `rex_yf_*` table; confirm the preview lists fields with sensible types, confidences, reasons, and (for i18n) the suffix→clang map.
3. Apply; verify the `yform_field` rows and that a merged `lang_*` column's JSON round-trips through `LangHelper::normalizeLanguageData()` to the expected per-language values, and that the `_<n>` member columns were dropped.
4. With `yform_lang_fields` (temporarily) unavailable, confirm i18n groups fall back to individually-labeled fields and the preview explains why.
5. With no AI key, confirm full functionality; with a key, confirm only LOW-confidence fields change and HIGH matches are untouched.

## 11. Assumptions

- `yform_lang_fields` lang storage format stays the `{clang_id,value}` JSON list documented in §6 (verified against v1.0.3 in `vegafilm`).
- YForm's standard `yform_field` columns (`choices`, `multiple`, `format`, etc.) accept the `params` values the rules emit; the fill-all-columns `insertRow()` already tolerates version-specific columns.
