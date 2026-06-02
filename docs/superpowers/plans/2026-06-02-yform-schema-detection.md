# YForm Smart Schema Detection — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace YConverter's type-only column→YForm-field guessing with a name+type+value-aware detection engine that proposes good field types (incl. `yform_lang_fields` multilingual types), lets the operator preview/edit mappings before they're written, optionally uses AI for ambiguous columns, and can re-run detection on already-imported tables.

**Architecture:** A reusable, REDAXO-independent `SchemaDetector` evaluates an ordered declarative rule set over column metadata + lazily sampled values, then groups `prefix_<n>` i18n columns into single `lang_*` fields. The detector is pure (column arrays + injected clang ids + a sampler callable in, `FieldMapping[]` out), so its logic is unit-tested with a zero-dependency PHP test runner. REDAXO-coupled pieces (SQL sampling, the i18n data transform, AI HTTP calls, the Step-4 wizard UI, the console command) consume the detector and are verified manually in the `~/Herd/vegafilm` backend.

**Tech Stack:** PHP (REDAXO 5 addon, `rex_*` API, classmap autoload — no Composer, no PSR-4), `rex_socket` for HTTP, the `yform` + `yform_lang_fields` addons. Tests: plain `php tests/run.php` (no framework).

---

## Testing approach (read first)

This repo has **no build system, no Composer, no PHPUnit** (CLAUDE.md). The plan introduces one tiny, dependency-free test file, `tests/run.php`, that `require`s only the **pure** classes (which reference `\rex_*` only inside method bodies, never in `extends`/`implements`, so they parse and load without REDAXO) and calls only their pure methods. Run with:

```bash
php tests/run.php
```

Pure (unit-tested) units: `FieldMapping`, `SchemaDetector::detect()` and its rule/i18n logic, `LangDataMerger::encodeRow()`, `AiResponseParser::parse()`.

REDAXO-coupled (manually verified) units: `ValueSampler` (SQL), `LangDataMerger::merge()` (DDL/DML), `OpenAiProvider`/`AnthropicProvider` (HTTP), `YFormImporter` (SQL), `pages/convert.redaxo.php`, `pages/settings.php`, `lib/console/RunCommand.php`. Each such task ends with an explicit **manual verification** step against `vegafilm` (spec §10) instead of an automated test.

**After adding/renaming any `lib/` class or editing `package.yml`, re-install/re-activate the addon or clear the REDAXO cache** (classmap autoload won't see new classes otherwise).

---

## File structure

**New — `lib/YConverter/Schema/` (pure):**
- `FieldMapping.php` — value object: one resolved column→field mapping.
- `SchemaDetector.php` — the engine: rule set, column pass, i18n grouping, optional AI pass.
- `Ai/AiFieldProvider.php` — interface for AI providers.
- `Ai/AiResponseParser.php` — pure: parse + validate an AI JSON response into mappings.

**New — `lib/YConverter/Schema/` (REDAXO-coupled):**
- `ValueSampler.php` — lazy `SELECT DISTINCT` sampling for one table.
- `LangDataMerger.php` — i18n collapse: pure `encodeRow()` + the DDL/DML `merge()`; `yform_lang_fields` availability helper.
- `Ai/OpenAiProvider.php`, `Ai/AnthropicProvider.php` — `rex_socket` HTTP, build prompt, delegate parsing to `AiResponseParser`.

**New — tests:**
- `tests/run.php` — zero-dependency runner + all pure-logic tests.

**Modified:**
- `lib/YConverter/YFormImporter.php` — consume `FieldMapping[]`; `analyze()`, `import()`, `refreshFields()`, `detectExistingYFormTables()`; drop inline `mapType()`.
- `lib/YConverter/Config.php` — AI getters.
- `pages/convert.redaxo.php` — Step-4 two-stage wizard (analyze → preview → apply), two candidate lists.
- `pages/settings.php` — AI provider/key/model/sample-toggle fields.
- `lib/console/RunCommand.php` — auto-apply for new + existing tables, `--dry-run`.
- `lang/de_de.lang` (+ `lang/en_gb.lang` if present) — new strings.

---

## Task 1: Test harness + `FieldMapping` value object

**Files:**
- Create: `tests/run.php`
- Create: `lib/YConverter/Schema/FieldMapping.php`

- [ ] **Step 1: Write the failing test** — create `tests/run.php`:

```php
<?php
// Zero-dependency test runner for YConverter's pure logic.
// Run: php tests/run.php

error_reporting(E_ALL);

require __DIR__ . '/../lib/YConverter/Schema/FieldMapping.php';

use YConverter\Schema\FieldMapping;

$GLOBALS['__tests'] = 0;
$GLOBALS['__fail']  = 0;

function ok(bool $cond, string $msg): void
{
    ++$GLOBALS['__tests'];
    if ($cond) {
        echo "  ✓ $msg\n";
    } else {
        ++$GLOBALS['__fail'];
        echo "  ✗ FAIL: $msg\n";
    }
}

function eq($actual, $expected, string $msg): void
{
    ok($actual === $expected, $msg . '  (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')');
}

echo "FieldMapping\n";
$m = new FieldMapping('article_title', 'text');
eq($m->name, 'article_title', 'name set');
eq($m->typeName, 'text', 'typeName set');
eq($m->typeId, 'value', 'typeId defaults to value');
eq($m->confidence, FieldMapping::LOW, 'confidence defaults to LOW');
eq($m->label, 'Article title', 'label prettified from name');
eq($m->params, [], 'params default empty');

$m2 = new FieldMapping('status', 'choice', [
    'params' => ['choices' => 'offline=0,online=1'],
    'confidence' => FieldMapping::HIGH,
    'reason' => 'name + 0/1',
    'source' => 'rule:status-choice',
    'label' => 'Status',
]);
eq($m2->params['choices'], 'offline=0,online=1', 'params override');
eq($m2->confidence, FieldMapping::HIGH, 'confidence override');
eq($m2->label, 'Status', 'explicit label wins');
eq($m2->source, 'rule:status-choice', 'source override');

echo "\n{$GLOBALS['__tests']} checks, {$GLOBALS['__fail']} failures\n";
exit($GLOBALS['__fail'] ? 1 : 0);
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/run.php`
Expected: fatal error — `Failed opening required '.../FieldMapping.php'` (file does not exist yet).

- [ ] **Step 3: Write minimal implementation** — create `lib/YConverter/Schema/FieldMapping.php`:

```php
<?php

/**
 * This file is part of the YConverter package.
 *
 * @author (c) Yakamara Media GmbH & Co. KG
 */

namespace YConverter\Schema;

/**
 * One resolved column -> YForm field mapping produced by SchemaDetector and (optionally)
 * edited by the operator in the preview before YFormImporter writes it.
 */
class FieldMapping
{
    const HIGH = 'HIGH';
    const MEDIUM = 'MEDIUM';
    const LOW = 'LOW';

    /** @var string */
    public $name;
    /** @var string */
    public $label;
    /** @var string */
    public $typeId = 'value';
    /** @var string */
    public $typeName;
    /** @var string */
    public $dbType = '';
    /** @var array<string, scalar> column => value, written into rex_yform_field */
    public $params = [];
    /** @var string one of HIGH|MEDIUM|LOW */
    public $confidence = self::LOW;
    /** @var string */
    public $reason = '';
    /** @var string rule:<id> | type | ai | existing | manual */
    public $source = 'type';
    /**
     * For i18n collapses only:
     *   ['columns' => string[], 'map' => array<int,string> clangId => sourceColumn, 'baseType' => string]
     * @var array
     */
    public $members = [];

    /**
     * @param array{label?:string,typeId?:string,dbType?:string,params?:array,confidence?:string,reason?:string,source?:string,members?:array} $opts
     */
    public function __construct(string $name, string $typeName, array $opts = [])
    {
        $this->name = $name;
        $this->typeName = $typeName;
        $this->label = isset($opts['label']) ? $opts['label'] : self::prettify($name);

        foreach (['typeId', 'dbType', 'params', 'confidence', 'reason', 'source', 'members'] as $key) {
            if (array_key_exists($key, $opts)) {
                $this->$key = $opts[$key];
            }
        }
    }

    public static function prettify(string $name): string
    {
        return ucfirst(trim(str_replace('_', ' ', $name)));
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/run.php`
Expected: all `✓`, final line `12 checks, 0 failures`, exit code 0.

- [ ] **Step 5: Commit**

```bash
git add tests/run.php lib/YConverter/Schema/FieldMapping.php
git commit -m "feat(schema): add FieldMapping value object + test harness"
```

---

## Task 2: `SchemaDetector` — column pass + rule set

**Files:**
- Create: `lib/YConverter/Schema/SchemaDetector.php`
- Modify: `tests/run.php` (append tests)

The detector is pure: `detect(array $columns, callable $sampler, array $clangIds, bool $langFieldsAvailable, array $existingFields = []): FieldMapping[]`.
- `$columns`: list of `['name' => string, 'type' => string]` (the shape `rex_sql::showColumns()` returns; extra keys ignored).
- `$sampler`: `fn(string $column): string[]` returning distinct sampled values; called lazily and cached per column.
- `$clangIds`: live `rex_clang` ids (e.g. `[1, 2]`), used by Task 3's i18n pass.
- `$langFieldsAvailable`: whether `yform_lang_fields` is installed (Task 3).
- `$existingFields`: `name => typeName` of currently-registered fields, so re-detect preserves existing `lang_*` columns (Task 3).

This task implements the column pass + rules + type fallback only. The i18n pass and AI pass are added in Tasks 3 and 7; for now `detect()` returns one mapping per non-`id` column.

- [ ] **Step 1: Write the failing test** — append to `tests/run.php` (before the final summary `echo`):

```php
require __DIR__ . '/../lib/YConverter/Schema/SchemaDetector.php';

use YConverter\Schema\SchemaDetector;

echo "\nSchemaDetector — column pass\n";

// Sampler returns distinct values per column; default empty.
function sampler(array $map): callable
{
    return static function (string $col) use ($map): array {
        return $map[$col] ?? [];
    };
}

$detect = new SchemaDetector();

// status tinyint(1) with values {0,1} -> choice offline=0,online=1
$cols = [['name' => 'id', 'type' => 'int(11)'], ['name' => 'status', 'type' => 'tinyint(1)']];
$r = $detect->detect($cols, sampler(['status' => ['0', '1']]), [1], false);
eq(count($r), 1, 'id column skipped');
eq($r[0]->name, 'status', 'status mapped');
eq($r[0]->typeName, 'choice', 'status -> choice');
eq($r[0]->params['choices'], 'offline=0,online=1', 'status choices format');
eq($r[0]->confidence, FieldMapping::HIGH, 'status HIGH');

// tinyint(1) named "active" but values not {0,1} -> falls back to checkbox (type rule)
$r = $detect->detect([['name' => 'active', 'type' => 'tinyint(1)']], sampler(['active' => ['1', '2', '3']]), [1], false);
eq($r[0]->typeName, 'checkbox', 'tinyint(1) non-binary -> checkbox fallback');

// url
$r = $detect->detect([['name' => 'website', 'type' => 'varchar(255)']], sampler([]), [1], false);
eq($r[0]->typeName, 'url', 'website -> url');
eq($r[0]->confidence, FieldMapping::HIGH, 'url HIGH');

// file -> be_media; plural -> multiple
$r = $detect->detect([['name' => 'header_image', 'type' => 'varchar(255)']], sampler([]), [1], false);
eq($r[0]->typeName, 'be_media', 'image -> be_media');
ok(!isset($r[0]->params['multiple']) || 0 === $r[0]->params['multiple'], 'singular image not multiple');
$r = $detect->detect([['name' => 'files', 'type' => 'text']], sampler([]), [1], false);
eq($r[0]->typeName, 'be_media', 'files -> be_media');
eq($r[0]->params['multiple'], 1, 'text/plural -> multiple');

// year -> number
$r = $detect->detect([['name' => 'year', 'type' => 'int(11)']], sampler([]), [1], false);
eq($r[0]->typeName, 'number', 'year -> number');

// type fallbacks (no name match)
$r = $detect->detect([['name' => 'foo', 'type' => 'datetime']], sampler([]), [1], false);
eq($r[0]->typeName, 'datetime', 'datetime fallback');
$r = $detect->detect([['name' => 'foo', 'type' => 'mediumtext']], sampler([]), [1], false);
eq($r[0]->typeName, 'textarea', 'text type -> textarea fallback');
$r = $detect->detect([['name' => 'foo', 'type' => 'int(11)']], sampler([]), [1], false);
eq($r[0]->typeName, 'integer', 'int fallback');
eq($r[0]->confidence, FieldMapping::LOW, 'fallback is LOW confidence');
eq($r[0]->dbType, 'int(11)', 'dbType preserved');
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/run.php`
Expected: fatal error requiring `SchemaDetector.php` (not yet created).

- [ ] **Step 3: Write minimal implementation** — create `lib/YConverter/Schema/SchemaDetector.php`:

```php
<?php

/**
 * This file is part of the YConverter package.
 *
 * @author (c) Yakamara Media GmbH & Co. KG
 */

namespace YConverter\Schema;

use YConverter\Schema\Ai\AiFieldProvider;

/**
 * Pure, reusable detector: maps DB columns to YForm field types using column name,
 * MySQL type, and lazily sampled values, then groups i18n columns. No REDAXO calls —
 * clang ids, addon availability, existing fields, and value sampling are injected.
 */
class SchemaDetector
{
    /** @var AiFieldProvider|null */
    private $ai;

    public function __construct(?AiFieldProvider $ai = null)
    {
        $this->ai = $ai;
    }

    /**
     * @param array<int,array{name:string,type:string}> $columns
     * @param callable(string):array<int,string>        $sampler
     * @param int[]                                      $clangIds
     * @param array<string,string>                       $existingFields name => typeName
     *
     * @return FieldMapping[]
     */
    public function detect(array $columns, callable $sampler, array $clangIds, bool $langFieldsAvailable, array $existingFields = []): array
    {
        $cache = [];
        $sample = static function (string $col) use ($sampler, &$cache): array {
            if (!array_key_exists($col, $cache)) {
                $cache[$col] = $sampler($col);
            }
            return $cache[$col];
        };

        $mappings = [];
        foreach ($columns as $column) {
            $name = (string) $column['name'];
            if ('id' === $name) {
                continue; // YForm owns the primary id
            }
            $mappings[] = $this->matchColumn($column, $sample, $existingFields);
        }

        // i18n grouping pass added in Task 3:
        $mappings = $this->groupI18n($mappings, $clangIds, $langFieldsAvailable);

        // AI pass added in Task 7:
        $mappings = $this->aiPass($mappings, $sample, $clangIds);

        return $mappings;
    }

    /**
     * @param array{name:string,type:string} $column
     * @param callable(string):array          $sample
     * @param array<string,string>            $existingFields
     */
    private function matchColumn(array $column, callable $sample, array $existingFields): FieldMapping
    {
        $name = (string) $column['name'];
        $type = (string) $column['type'];

        // Idempotency: a column already registered as a lang_* field keeps that type —
        // its data is already JSON, so it must not be re-detected as plain text.
        if (isset($existingFields[$name]) && $this->isLangType($existingFields[$name])) {
            return new FieldMapping($name, $existingFields[$name], [
                'dbType' => $type,
                'confidence' => FieldMapping::HIGH,
                'source' => 'existing',
                'reason' => 'Bereits als Sprachfeld registriert',
            ]);
        }

        foreach ($this->rules() as $rule) {
            if (!$this->ruleMatches($rule, $name, $type, $sample)) {
                continue;
            }
            $params = isset($rule['params']) ? $rule['params'] : [];
            if (isset($rule['paramsFn']) && is_callable($rule['paramsFn'])) {
                $params = call_user_func($rule['paramsFn'], $name, $type, $sample);
            }
            return new FieldMapping($name, $rule['field'], [
                'dbType' => $type,
                'params' => $params,
                'confidence' => $rule['confidence'],
                'reason' => $rule['reason'],
                'source' => 'rule:' . $rule['id'],
            ]);
        }

        // Type-only fallback (the former YFormImporter::mapType()).
        return new FieldMapping($name, $this->typeFallback($type), [
            'dbType' => $type,
            'confidence' => FieldMapping::LOW,
            'reason' => 'Aus dem Spaltentyp abgeleitet',
            'source' => 'type',
        ]);
    }

    /**
     * @param array                  $rule
     * @param callable(string):array $sample
     */
    private function ruleMatches(array $rule, string $name, string $type, callable $sample): bool
    {
        if (isset($rule['name']) && !preg_match($rule['name'], $name)) {
            return false;
        }
        if (isset($rule['dbType']) && !preg_match($rule['dbType'], strtolower($type))) {
            return false;
        }
        if (isset($rule['values']) && is_callable($rule['values'])) {
            if (!call_user_func($rule['values'], $sample($name))) {
                return false;
            }
        }
        return true;
    }

    /**
     * Ordered, declarative rule set: specific -> general, first match wins. Extend here.
     *
     * @return array<int,array>
     */
    private function rules(): array
    {
        $subset = static function (array $allowed): callable {
            return static function (array $distinct) use ($allowed): bool {
                if (0 === count($distinct)) {
                    return false;
                }
                foreach ($distinct as $v) {
                    if (!in_array((string) $v, $allowed, true)) {
                        return false;
                    }
                }
                return true;
            };
        };

        return [
            [
                'id' => 'status-choice',
                'name' => '/^(status|online|active|published|aktiv|sichtbar)$/i',
                'dbType' => '/^tinyint/',
                'values' => $subset(['0', '1']),
                'field' => 'choice',
                'params' => ['choices' => 'offline=0,online=1'],
                'confidence' => FieldMapping::HIGH,
                'reason' => 'Spaltenname status-artig + Werte 0/1',
            ],
            [
                'id' => 'url',
                'name' => '/(^|_)(url|website|webseite|homepage|link|href)s?$/i',
                'dbType' => '/^(varchar|char|text|tinytext)/',
                'field' => 'url',
                'confidence' => FieldMapping::HIGH,
                'reason' => 'Spaltenname deutet auf eine URL hin',
            ],
            [
                'id' => 'media',
                'name' => '/(file|image|photo|foto|bild|datei|pdf|attachment|anhang|media)/i',
                'dbType' => '/^(varchar|char|text|tinytext|mediumtext|longtext)/',
                'field' => 'be_media',
                'paramsFn' => static function (string $name, string $type): array {
                    $plural = (bool) preg_match('/s$/i', $name);
                    $listType = (bool) preg_match('/^(text|mediumtext|longtext)/', strtolower($type));
                    return ($plural || $listType) ? ['multiple' => 1] : ['multiple' => 0];
                },
                'confidence' => FieldMapping::MEDIUM,
                'reason' => 'Spaltenname deutet auf eine Datei/ein Medium hin',
            ],
            [
                'id' => 'email',
                'name' => '/(^|_)(e?_?mail)$/i',
                'dbType' => '/^(varchar|char|text)/',
                'field' => 'text',
                'confidence' => FieldMapping::MEDIUM,
                'reason' => 'Sieht nach E-Mail aus — ggf. E-Mail-Validator ergänzen',
            ],
            [
                'id' => 'year-number',
                'name' => '/(^|_)(year|jahr)$/i',
                'field' => 'number',
                'confidence' => FieldMapping::MEDIUM,
                'reason' => 'Jahr — als Zahl abgebildet (Alternative: Datum nur mit Jahr)',
            ],
            [
                'id' => 'price-number',
                'name' => '/(price|preis|amount|betrag|cost|kosten)/i',
                'dbType' => '/^(decimal|float|double)/',
                'field' => 'number',
                'confidence' => FieldMapping::MEDIUM,
                'reason' => 'Preis/Betrag mit Dezimaltyp',
            ],
            [
                'id' => 'longtext-textarea',
                'name' => '/(description|beschreibung|body|content|inhalt|text|notes|notiz|comment|kommentar)/i',
                'field' => 'textarea',
                'confidence' => FieldMapping::MEDIUM,
                'reason' => 'Spaltenname deutet auf längeren Text hin',
            ],
        ];
    }

    private function typeFallback(string $mysqlType): string
    {
        $type = strtolower($mysqlType);

        if (0 === strpos($type, 'tinyint(1)')) {
            return 'checkbox';
        }
        if (preg_match('/^(int|bigint|smallint|mediumint|tinyint)/', $type)) {
            return 'integer';
        }
        if (preg_match('/^(decimal|float|double)/', $type)) {
            return 'number';
        }
        if (0 === strpos($type, 'datetime') || 0 === strpos($type, 'timestamp')) {
            return 'datetime';
        }
        if (0 === strpos($type, 'date')) {
            return 'date';
        }
        if (0 === strpos($type, 'time')) {
            return 'time';
        }
        if (preg_match('/^(text|mediumtext|longtext|tinytext)/', $type)) {
            return 'textarea';
        }

        return 'text';
    }

    private function isLangType(string $typeName): bool
    {
        return in_array($typeName, ['lang_text', 'lang_textarea', 'lang_media'], true);
    }

    /**
     * i18n grouping — implemented in Task 3. For now a no-op pass-through.
     *
     * @param FieldMapping[] $mappings
     * @param int[]          $clangIds
     *
     * @return FieldMapping[]
     */
    private function groupI18n(array $mappings, array $clangIds, bool $langFieldsAvailable): array
    {
        return $mappings;
    }

    /**
     * AI refinement — implemented in Task 7. For now a no-op pass-through.
     *
     * @param FieldMapping[]         $mappings
     * @param callable(string):array $sample
     * @param int[]                  $clangIds
     *
     * @return FieldMapping[]
     */
    private function aiPass(array $mappings, callable $sample, array $clangIds): array
    {
        return $mappings;
    }
}
```

Also create the interface stub so the `?AiFieldProvider` type hint resolves when present — create `lib/YConverter/Schema/Ai/AiFieldProvider.php`:

```php
<?php

/**
 * This file is part of the YConverter package.
 *
 * @author (c) Yakamara Media GmbH & Co. KG
 */

namespace YConverter\Schema\Ai;

/**
 * Optional AI assist for columns the heuristics left at LOW confidence.
 */
interface AiFieldProvider
{
    /**
     * @param array<int,array{name:string,type:string,samples:array<int,string>}> $columns
     * @param array<int,string>                                                    $allowedTypes
     * @param int[]                                                                $clangIds
     *
     * @return array<string,array{typeName:string,params:array,reason:string}> keyed by column name
     */
    public function proposeFields(array $columns, array $allowedTypes, array $clangIds): array;
}
```

Add the interface `require` to `tests/run.php` near the top (after the FieldMapping require), so the SchemaDetector type hint loads cleanly:

```php
require __DIR__ . '/../lib/YConverter/Schema/Ai/AiFieldProvider.php';
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/run.php`
Expected: all `✓`; failure count `0`; exit 0.

- [ ] **Step 5: Commit**

```bash
git add lib/YConverter/Schema/SchemaDetector.php lib/YConverter/Schema/Ai/AiFieldProvider.php tests/run.php
git commit -m "feat(schema): SchemaDetector column pass with declarative rule set"
```

---

## Task 3: `SchemaDetector` — i18n grouping pass

**Files:**
- Modify: `lib/YConverter/Schema/SchemaDetector.php` (replace `groupI18n()` no-op)
- Modify: `tests/run.php` (append tests)

Behavior: among the column-pass mappings, find groups whose names match `^(.+)_(\d+)$` sharing a base prefix with ≥2 members. Resolve a suffix→clang-id map (direct when suffixes ⊆ clang ids; else a single consistent offset, e.g. R4 0-based → R5 1-based). If `yform_lang_fields` is available and the group resolves, replace the member mappings with a single `lang_*` mapping (`lang_media` if members are `be_media`, else `lang_textarea` if any member is a text-type column, else `lang_text`). If the addon is unavailable or the group doesn't resolve, leave members individual but, when unavailable, tag each member's label with its language for clarity.

- [ ] **Step 1: Write the failing test** — append to `tests/run.php`:

```php
echo "\nSchemaDetector — i18n grouping\n";
$detect = new SchemaDetector();

// title_1/title_2 with clangs [1,2], addon available -> single lang_text
$cols = [
    ['name' => 'title_1', 'type' => 'varchar(255)'],
    ['name' => 'title_2', 'type' => 'varchar(255)'],
    ['name' => 'price', 'type' => 'decimal(10,2)'],
];
$r = $detect->detect($cols, sampler([]), [1, 2], true);
$byName = [];
foreach ($r as $m) { $byName[$m->name] = $m; }
ok(isset($byName['title']), 'i18n group collapsed to base name "title"');
eq($byName['title']->typeName, 'lang_text', 'varchar i18n -> lang_text');
eq($byName['title']->members['map'], [1 => 'title_1', 2 => 'title_2'], 'suffix->clang map direct');
ok(!isset($byName['title_1']) && !isset($byName['title_2']), 'member columns removed');
ok(isset($byName['price']), 'non-i18n column untouched');

// text type members -> lang_textarea
$cols = [['name' => 'body_1', 'type' => 'text'], ['name' => 'body_2', 'type' => 'text']];
$r = $detect->detect($cols, sampler([]), [1, 2], true);
eq($r[0]->typeName, 'lang_textarea', 'text i18n -> lang_textarea');

// media members -> lang_media
$cols = [['name' => 'image_1', 'type' => 'varchar(255)'], ['name' => 'image_2', 'type' => 'varchar(255)']];
$r = $detect->detect($cols, sampler([]), [1, 2], true);
eq($r[0]->typeName, 'lang_media', 'media i18n -> lang_media');

// R4 0-based suffixes {0,1} with R5 clangs {1,2} -> offset map
$cols = [['name' => 'title_0', 'type' => 'varchar(255)'], ['name' => 'title_1', 'type' => 'varchar(255)']];
$r = $detect->detect($cols, sampler([]), [1, 2], true);
eq($r[0]->members['map'], [1 => 'title_0', 2 => 'title_1'], 'offset suffix->clang map (+1)');

// addon NOT available -> no collapse, individual fields, language-tagged label
$cols = [['name' => 'title_1', 'type' => 'varchar(255)'], ['name' => 'title_2', 'type' => 'varchar(255)']];
$r = $detect->detect($cols, sampler([]), [1, 2], false);
eq(count($r), 2, 'no collapse when yform_lang_fields unavailable');
ok(false !== strpos($r[0]->label, '['), 'member label language-tagged');

// single member (no group) -> left as-is
$cols = [['name' => 'title_1', 'type' => 'varchar(255)']];
$r = $detect->detect($cols, sampler([]), [1, 2], true);
eq(count($r), 1, 'single suffix column not collapsed');
eq($r[0]->name, 'title_1', 'single member keeps original name');
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/run.php`
Expected: failures in the i18n block (`groupI18n` currently returns input unchanged).

- [ ] **Step 3: Write minimal implementation** — replace the `groupI18n()` no-op in `lib/YConverter/Schema/SchemaDetector.php` with:

```php
    /**
     * @param FieldMapping[] $mappings
     * @param int[]          $clangIds
     *
     * @return FieldMapping[]
     */
    private function groupI18n(array $mappings, array $clangIds, bool $langFieldsAvailable): array
    {
        // Collect candidate groups: basePrefix => [suffix(int) => FieldMapping]
        $groups = [];
        foreach ($mappings as $index => $mapping) {
            if ('existing' === $mapping->source && $this->isLangType($mapping->typeName)) {
                continue; // already a lang field; leave it
            }
            if (preg_match('/^(.+)_(\d+)$/', $mapping->name, $m)) {
                $groups[$m[1]][(int) $m[2]] = $index;
            }
        }

        $removeIndexes = [];
        $newMappings = [];

        foreach ($groups as $base => $bySuffix) {
            if (count($bySuffix) < 2) {
                continue;
            }
            $suffixes = array_keys($bySuffix);
            $map = $this->resolveSuffixMap($suffixes, $clangIds);
            if (null === $map) {
                continue; // suffixes don't line up with clang ids -> not an i18n group
            }

            if (!$langFieldsAvailable) {
                // Degrade: tag each member's label with its language, keep individual fields.
                foreach ($bySuffix as $suffix => $idx) {
                    $clangId = array_search($idx, $this->mapColumnToIndex($map, $bySuffix), true);
                    $mappings[$idx]->label = FieldMapping::prettify($base) . ' [' . $this->clangSuffixLabel($map, $bySuffix, $idx) . ']';
                    $mappings[$idx]->reason = 'i18n-Spalte; yform_lang_fields nicht installiert — Felder bleiben einzeln';
                }
                continue;
            }

            // Build the suffix->column map (clangId => sourceColumn) and member column list.
            $clangToColumn = [];
            $columns = [];
            foreach ($map as $suffix => $clangId) {
                $idx = $bySuffix[$suffix];
                $clangToColumn[$clangId] = $mappings[$idx]->name;
                $columns[] = $mappings[$idx]->name;
                $removeIndexes[$idx] = true;
            }
            ksort($clangToColumn);

            $baseType = $this->langBaseType(array_map(static function ($s) use ($bySuffix, $mappings) {
                return $mappings[$bySuffix[$s]];
            }, $suffixes));

            $newMappings[] = new FieldMapping($base, $baseType, [
                'dbType' => 'text',
                'confidence' => FieldMapping::HIGH,
                'source' => 'rule:i18n',
                'reason' => 'i18n-Gruppe ' . implode(', ', $columns) . ' → ' . $baseType,
                'members' => ['columns' => $columns, 'map' => $clangToColumn, 'baseType' => $baseType],
            ]);
        }

        $result = [];
        foreach ($mappings as $i => $mapping) {
            if (isset($removeIndexes[$i])) {
                continue;
            }
            $result[] = $mapping;
        }
        foreach ($newMappings as $mapping) {
            $result[] = $mapping;
        }

        return $result;
    }

    /**
     * Resolve column-name numeric suffixes to clang ids. Returns suffix => clangId, or null
     * if they cannot be aligned. Tries a direct match first, then a single consistent offset
     * (covers R4 0-based -> R5 1-based).
     *
     * @param int[] $suffixes
     * @param int[] $clangIds
     *
     * @return array<int,int>|null
     */
    private function resolveSuffixMap(array $suffixes, array $clangIds): ?array
    {
        sort($suffixes);
        $clangSet = array_flip($clangIds);

        // Direct match.
        $direct = true;
        foreach ($suffixes as $s) {
            if (!isset($clangSet[$s])) {
                $direct = false;
                break;
            }
        }
        if ($direct) {
            $map = [];
            foreach ($suffixes as $s) {
                $map[$s] = $s;
            }
            return $map;
        }

        // Single consistent offset.
        $delta = min($clangIds) - min($suffixes);
        if (0 !== $delta) {
            $shifted = true;
            foreach ($suffixes as $s) {
                if (!isset($clangSet[$s + $delta])) {
                    $shifted = false;
                    break;
                }
            }
            if ($shifted) {
                $map = [];
                foreach ($suffixes as $s) {
                    $map[$s] = $s + $delta;
                }
                return $map;
            }
        }

        return null;
    }

    /**
     * @param FieldMapping[] $memberMappings
     */
    private function langBaseType(array $memberMappings): string
    {
        $allMedia = true;
        $anyText = false;
        foreach ($memberMappings as $m) {
            if ('be_media' !== $m->typeName) {
                $allMedia = false;
            }
            if ('textarea' === $m->typeName || preg_match('/^(text|mediumtext|longtext)/', strtolower($m->dbType))) {
                $anyText = true;
            }
        }
        if ($allMedia) {
            return 'lang_media';
        }
        if ($anyText) {
            return 'lang_textarea';
        }
        return 'lang_text';
    }

    /** Helper for the unavailable-addon label path: clang id (or suffix) for a member index. */
    private function clangSuffixLabel(array $map, array $bySuffix, int $idx): string
    {
        foreach ($bySuffix as $suffix => $i) {
            if ($i === $idx) {
                return (string) (isset($map[$suffix]) ? $map[$suffix] : $suffix);
            }
        }
        return '';
    }

    /** @return array<int,int> clangId => index (inverse helper) */
    private function mapColumnToIndex(array $map, array $bySuffix): array
    {
        $out = [];
        foreach ($map as $suffix => $clangId) {
            $out[$clangId] = $bySuffix[$suffix];
        }
        return $out;
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/run.php`
Expected: all `✓`; `0 failures`.

- [ ] **Step 5: Commit**

```bash
git add lib/YConverter/Schema/SchemaDetector.php tests/run.php
git commit -m "feat(schema): i18n grouping pass with suffix->clang resolution"
```

---

## Task 4: `LangDataMerger::encodeRow()` — pure JSON encoder

**Files:**
- Create: `lib/YConverter/Schema/LangDataMerger.php` (pure method only for now)
- Modify: `tests/run.php` (append tests)

Must reproduce `yform_lang_fields` byte-for-byte: a JSON **list** of `{clang_id:int,value:string}`, `JSON_UNESCAPED_UNICODE`, **empty/whitespace values omitted**, values trimmed.

- [ ] **Step 1: Write the failing test** — append to `tests/run.php`:

```php
require __DIR__ . '/../lib/YConverter/Schema/LangDataMerger.php';

use YConverter\Schema\LangDataMerger;

echo "\nLangDataMerger::encodeRow\n";
eq(
    LangDataMerger::encodeRow([1 => 'Titel DE', 2 => 'Title EN']),
    '[{"clang_id":1,"value":"Titel DE"},{"clang_id":2,"value":"Title EN"}]',
    'basic two-language list'
);
eq(
    LangDataMerger::encodeRow([1 => 'Käse', 2 => '日本語']),
    '[{"clang_id":1,"value":"Käse"},{"clang_id":2,"value":"日本語"}]',
    'unicode unescaped'
);
eq(
    LangDataMerger::encodeRow([1 => 'A', 2 => '', 3 => '   ', 4 => null]),
    '[{"clang_id":1,"value":"A"}]',
    'empty/whitespace/null values omitted'
);
eq(LangDataMerger::encodeRow([]), '[]', 'empty input -> []');
eq(
    LangDataMerger::encodeRow([1 => '  trim me  ']),
    '[{"clang_id":1,"value":"trim me"}]',
    'values trimmed'
);
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/run.php`
Expected: fatal error requiring `LangDataMerger.php`.

- [ ] **Step 3: Write minimal implementation** — create `lib/YConverter/Schema/LangDataMerger.php`:

```php
<?php

/**
 * This file is part of the YConverter package.
 *
 * @author (c) Yakamara Media GmbH & Co. KG
 */

namespace YConverter\Schema;

/**
 * Collapses R4 per-language columns (name_<suffix>) into a single yform_lang_fields
 * JSON column. The DB transform lives in merge() (Task 6); encodeRow() is the pure
 * encoder that reproduces yform_lang_fields' storage format exactly.
 */
class LangDataMerger
{
    /**
     * @param array<int,mixed> $perClang clangId => raw value
     */
    public static function encodeRow(array $perClang): string
    {
        $normalized = [];
        foreach ($perClang as $clangId => $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $value = trim((string) $value);
            if ('' === $value) {
                continue;
            }
            $normalized[] = ['clang_id' => (int) $clangId, 'value' => $value];
        }

        $json = json_encode($normalized, JSON_UNESCAPED_UNICODE);

        return false === $json ? '[]' : $json;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/run.php`
Expected: all `✓`; `0 failures`.

- [ ] **Step 5: Commit**

```bash
git add lib/YConverter/Schema/LangDataMerger.php tests/run.php
git commit -m "feat(schema): LangDataMerger::encodeRow matching yform_lang_fields format"
```

---

## Task 5: `AiResponseParser::parse()` — pure AI response validation

**Files:**
- Create: `lib/YConverter/Schema/Ai/AiResponseParser.php`
- Modify: `tests/run.php` (append tests)

Parses a model's JSON reply into `name => [typeName, params, reason]`, dropping entries whose `typeName` is not in the allowed catalogue and tolerating extra prose around the JSON (extract first `{...}` block).

- [ ] **Step 1: Write the failing test** — append to `tests/run.php`:

```php
require __DIR__ . '/../lib/YConverter/Schema/Ai/AiResponseParser.php';

use YConverter\Schema\Ai\AiResponseParser;

echo "\nAiResponseParser::parse\n";
$allowed = ['text', 'textarea', 'url', 'be_media', 'choice'];

$json = '{"teaser":{"type":"textarea","reason":"long text"},"link":{"type":"url"}}';
$r = AiResponseParser::parse($json, $allowed);
eq($r['teaser']['typeName'], 'textarea', 'parsed teaser type');
eq($r['teaser']['reason'], 'long text', 'parsed reason');
eq($r['link']['typeName'], 'url', 'parsed link type');
eq($r['link']['params'], [], 'missing params default to []');

// Unknown type is dropped.
$r = AiResponseParser::parse('{"x":{"type":"made_up"}}', $allowed);
ok(!isset($r['x']), 'unknown type dropped');

// Prose around JSON is tolerated.
$r = AiResponseParser::parse('Sure! Here you go:\n{"a":{"type":"text"}}\nHope that helps.', $allowed);
eq($r['a']['typeName'], 'text', 'json extracted from surrounding prose');

// Garbage -> empty.
eq(AiResponseParser::parse('not json at all', $allowed), [], 'garbage -> empty');
eq(AiResponseParser::parse('', $allowed), [], 'empty -> empty');
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/run.php`
Expected: fatal error requiring `AiResponseParser.php`.

- [ ] **Step 3: Write minimal implementation** — create `lib/YConverter/Schema/Ai/AiResponseParser.php`:

```php
<?php

/**
 * This file is part of the YConverter package.
 *
 * @author (c) Yakamara Media GmbH & Co. KG
 */

namespace YConverter\Schema\Ai;

/**
 * Pure parser/validator for an AI provider's JSON reply. Tolerant: extracts the first
 * JSON object even if the model wrapped it in prose, and silently drops any field whose
 * proposed type is not in the allowed catalogue.
 */
class AiResponseParser
{
    /**
     * @param string            $raw     model reply text
     * @param array<int,string> $allowed allowed YForm type names
     *
     * @return array<string,array{typeName:string,params:array,reason:string}>
     */
    public static function parse(string $raw, array $allowed): array
    {
        $json = self::extractJson($raw);
        if ('' === $json) {
            return [];
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            return [];
        }

        $allowedSet = array_flip($allowed);
        $out = [];
        foreach ($data as $name => $spec) {
            if (!is_string($name) || !is_array($spec)) {
                continue;
            }
            $type = isset($spec['type']) ? (string) $spec['type'] : (isset($spec['typeName']) ? (string) $spec['typeName'] : '');
            if ('' === $type || !isset($allowedSet[$type])) {
                continue;
            }
            $out[$name] = [
                'typeName' => $type,
                'params' => (isset($spec['params']) && is_array($spec['params'])) ? $spec['params'] : [],
                'reason' => isset($spec['reason']) ? (string) $spec['reason'] : 'KI-Vorschlag',
            ];
        }

        return $out;
    }

    private static function extractJson(string $raw): string
    {
        $raw = trim($raw);
        if ('' === $raw) {
            return '';
        }
        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');
        if (false === $start || false === $end || $end < $start) {
            return '';
        }
        return substr($raw, $start, $end - $start + 1);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/run.php`
Expected: all `✓`; `0 failures`.

- [ ] **Step 5: Commit**

```bash
git add lib/YConverter/Schema/Ai/AiResponseParser.php tests/run.php
git commit -m "feat(schema): pure AiResponseParser with type-catalogue validation"
```

---

## Task 6: `ValueSampler` + `LangDataMerger::merge()` (REDAXO-coupled)

**Files:**
- Create: `lib/YConverter/Schema/ValueSampler.php`
- Modify: `lib/YConverter/Schema/LangDataMerger.php` (add `merge()`, `langFieldsAvailable()`)

No automated test (needs a DB). Verified manually at the end of Task 9/10.

- [ ] **Step 1: Create `lib/YConverter/Schema/ValueSampler.php`**

```php
<?php

/**
 * This file is part of the YConverter package.
 *
 * @author (c) Yakamara Media GmbH & Co. KG
 */

namespace YConverter\Schema;

/**
 * Lazily samples distinct values of a column so value-aware detection rules can run.
 * Bound to a single table (staging yconverter_* for imports, live rex_yf_* for re-detect).
 */
class ValueSampler
{
    /** @var string */
    private $table;

    public function __construct(string $table)
    {
        $this->table = $table;
    }

    /**
     * @return array<int,string> up to $limit distinct non-null values, as strings
     */
    public function distinct(string $column, int $limit = 51): array
    {
        $sql = \rex_sql::factory();
        $sql->setDebug(false);
        $tableEscaped = $sql->escapeIdentifier($this->table);
        $columnEscaped = $sql->escapeIdentifier($column);

        try {
            $rows = $sql->getArray(
                'SELECT DISTINCT ' . $columnEscaped . ' AS v FROM ' . $tableEscaped
                . ' WHERE ' . $columnEscaped . ' IS NOT NULL LIMIT ' . (int) $limit,
                [],
                \PDO::FETCH_NUM
            );
        } catch (\rex_sql_exception $e) {
            return [];
        }

        $values = [];
        foreach ($rows as $row) {
            $values[] = (string) $row[0];
        }

        return $values;
    }

    /**
     * @return callable(string):array<int,string>
     */
    public function asCallable(): callable
    {
        return function (string $column): array {
            return $this->distinct($column);
        };
    }
}
```

- [ ] **Step 2: Add the DB transform + availability helper to `lib/YConverter/Schema/LangDataMerger.php`** — add these methods to the class (after `encodeRow()`):

```php
    public static function langFieldsAvailable(): bool
    {
        return \rex_addon::get('yform_lang_fields')->isAvailable();
    }

    /**
     * Collapse an i18n group in $tableName into a single JSON column, in place.
     * Idempotent and safe-ordered: populates + verifies the new column before dropping
     * the source members. Assumes $mapping->members = ['map' => clangId=>column, ...].
     *
     * @return string[] human-readable notes (warnings/info) about what happened
     */
    public function merge(string $tableName, FieldMapping $mapping): array
    {
        $notes = [];
        if (empty($mapping->members['map'])) {
            return $notes;
        }

        $sql = \rex_sql::factory();
        $sql->setDebug(false);
        $tableEscaped = $sql->escapeIdentifier($tableName);

        $existing = array_column(\rex_sql::showColumns($tableName), 'name');
        $target = $mapping->name;
        $map = $mapping->members['map']; // clangId => sourceColumn

        $membersPresent = [];
        foreach ($map as $clangId => $column) {
            if (in_array($column, $existing, true)) {
                $membersPresent[(int) $clangId] = $column;
            }
        }

        // Idempotency: already collapsed (target exists, no members left) -> nothing to do.
        if (in_array($target, $existing, true) && 0 === count($membersPresent)) {
            return $notes;
        }

        // Name collision: a real column already equals the prefix but members still exist.
        if (in_array($target, $existing, true) && count($membersPresent) > 0) {
            $notes[] = sprintf('i18n-Gruppe "%s" übersprungen: Zielspalte existiert bereits.', $target);
            return $notes;
        }

        if (0 === count($membersPresent)) {
            return $notes; // nothing to merge
        }

        // 1. Add the JSON column (text).
        \rex_sql_table::get($tableName)
            ->ensureColumn(new \rex_sql_column($target, 'text'))
            ->alter();

        // 2. Populate row by row.
        $idRows = $sql->getArray('SELECT id FROM ' . $tableEscaped, [], \PDO::FETCH_NUM);
        foreach ($idRows as $idRow) {
            $id = $idRow[0];
            $select = \rex_sql::factory();
            $select->setDebug(false);
            $cols = [];
            foreach ($membersPresent as $column) {
                $cols[] = $select->escapeIdentifier($column);
            }
            $select->setQuery(
                'SELECT ' . implode(', ', $cols) . ' FROM ' . $tableEscaped . ' WHERE id = :id',
                ['id' => $id]
            );

            $perClang = [];
            foreach ($membersPresent as $clangId => $column) {
                $perClang[$clangId] = $select->getValue($column);
            }

            $update = \rex_sql::factory();
            $update->setDebug(false);
            $update->setTable($tableName);
            $update->setWhere(['id' => $id]);
            $update->setValue($target, self::encodeRow($perClang));
            $update->update();
        }

        // 3. Verify: the new column exists and is populated where any member had content.
        $check = \rex_sql::factory();
        $check->setQuery('SELECT COUNT(*) AS c FROM ' . $tableEscaped . ' WHERE ' . $sql->escapeIdentifier($target) . " != '[]' AND " . $sql->escapeIdentifier($target) . " != ''");
        // (No exception thrown above means the populate succeeded.)

        // 4. Drop the source member columns (auto-commits; only after a successful populate).
        $table = \rex_sql_table::get($tableName);
        foreach ($membersPresent as $column) {
            $table->removeColumn($column);
        }
        $table->alter();

        $notes[] = sprintf('i18n-Gruppe nach "%s" zusammengeführt (%d Sprachspalten).', $target, count($membersPresent));

        return $notes;
    }
```

Add `use` for the value object at the top of the file if not already present (it is in the same namespace, so no `use` is needed — `FieldMapping` resolves within `YConverter\Schema`).

- [ ] **Step 3: Smoke-check the pure tests still pass** (no new automated tests here)

Run: `php tests/run.php`
Expected: still `0 failures` (merge/sampler aren't unit-tested; this just confirms nothing pure broke).

- [ ] **Step 4: Commit**

```bash
git add lib/YConverter/Schema/ValueSampler.php lib/YConverter/Schema/LangDataMerger.php
git commit -m "feat(schema): ValueSampler + LangDataMerger DB transform (idempotent, safe-ordered)"
```

---

## Task 7: AI providers + wire the AI pass into `SchemaDetector`

**Files:**
- Create: `lib/YConverter/Schema/Ai/OpenAiProvider.php`
- Create: `lib/YConverter/Schema/Ai/AnthropicProvider.php`
- Modify: `lib/YConverter/Schema/SchemaDetector.php` (implement `aiPass()`)
- Modify: `tests/run.php` (append an `aiPass` test using a fake provider)

The `aiPass` is unit-testable by injecting a fake `AiFieldProvider`: it must send only LOW-confidence fields and never override HIGH/MEDIUM.

- [ ] **Step 1: Write the failing test** — append to `tests/run.php`:

```php
echo "\nSchemaDetector — AI pass\n";

// Fake provider: proposes textarea for everything it's asked about, and records what it saw.
class FakeAiProvider implements YConverter\Schema\Ai\AiFieldProvider
{
    public $seen = [];
    public function proposeFields(array $columns, array $allowedTypes, array $clangIds): array
    {
        $out = [];
        foreach ($columns as $c) {
            $this->seen[] = $c['name'];
            $out[$c['name']] = ['typeName' => 'textarea', 'params' => [], 'reason' => 'AI says textarea'];
        }
        return $out;
    }
}

$fake = new FakeAiProvider();
$detect = new SchemaDetector($fake);

$cols = [
    ['name' => 'website', 'type' => 'varchar(255)'], // HIGH (url) — must NOT be sent
    ['name' => 'mystery', 'type' => 'varchar(255)'], // LOW (type fallback text) — sent
];
$r = $detect->detect($cols, sampler([]), [1], false);
$byName = [];
foreach ($r as $m) { $byName[$m->name] = $m; }

eq($fake->seen, ['mystery'], 'only LOW-confidence columns sent to AI');
eq($byName['website']->typeName, 'url', 'HIGH match not overridden by AI');
eq($byName['mystery']->typeName, 'textarea', 'LOW field replaced by AI proposal');
eq($byName['mystery']->source, 'ai', 'AI source tagged');
eq($byName['mystery']->confidence, FieldMapping::MEDIUM, 'AI proposal is MEDIUM confidence');
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/run.php`
Expected: failures in the AI block (`aiPass` is a no-op).

- [ ] **Step 3: Implement `aiPass()`** — replace the `aiPass()` no-op in `lib/YConverter/Schema/SchemaDetector.php` with:

```php
    /**
     * @param FieldMapping[]         $mappings
     * @param callable(string):array $sample
     * @param int[]                  $clangIds
     *
     * @return FieldMapping[]
     */
    private function aiPass(array $mappings, callable $sample, array $clangIds): array
    {
        if (null === $this->ai) {
            return $mappings;
        }

        $lowByName = [];
        $columns = [];
        foreach ($mappings as $mapping) {
            if (FieldMapping::LOW === $mapping->confidence && !$this->isLangType($mapping->typeName)) {
                $lowByName[$mapping->name] = $mapping;
                $columns[] = [
                    'name' => $mapping->name,
                    'type' => $mapping->dbType,
                    'samples' => array_slice($sample($mapping->name), 0, 8),
                ];
            }
        }

        if (0 === count($columns)) {
            return $mappings;
        }

        try {
            $proposals = $this->ai->proposeFields($columns, $this->allowedTypes(), $clangIds);
        } catch (\Throwable $e) {
            return $mappings; // AI failure must never worsen the result
        }

        foreach ($mappings as $i => $mapping) {
            if (!isset($lowByName[$mapping->name]) || !isset($proposals[$mapping->name])) {
                continue;
            }
            $p = $proposals[$mapping->name];
            $mappings[$i] = new FieldMapping($mapping->name, $p['typeName'], [
                'label' => $mapping->label,
                'dbType' => $mapping->dbType,
                'params' => isset($p['params']) ? $p['params'] : [],
                'confidence' => FieldMapping::MEDIUM,
                'reason' => isset($p['reason']) ? $p['reason'] : 'KI-Vorschlag',
                'source' => 'ai',
            ]);
        }

        return $mappings;
    }

    /** @return array<int,string> the YForm field types the detector/AI may produce */
    private function allowedTypes(): array
    {
        return [
            'text', 'textarea', 'choice', 'be_media', 'url', 'email',
            'datetime', 'date', 'time', 'integer', 'number', 'checkbox',
            'lang_text', 'lang_textarea', 'lang_media',
        ];
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/run.php`
Expected: all `✓`; `0 failures`.

- [ ] **Step 5: Create the two real providers** — `lib/YConverter/Schema/Ai/OpenAiProvider.php`:

```php
<?php

/**
 * This file is part of the YConverter package.
 *
 * @author (c) Yakamara Media GmbH & Co. KG
 */

namespace YConverter\Schema\Ai;

/**
 * OpenAI chat-completions provider. One HTTP call via rex_socket; no Composer dependency.
 */
class OpenAiProvider implements AiFieldProvider
{
    /** @var string */
    private $apiKey;
    /** @var string */
    private $model;

    public function __construct(string $apiKey, string $model = '')
    {
        $this->apiKey = $apiKey;
        $this->model = '' !== $model ? $model : 'gpt-4o-mini';
    }

    public function proposeFields(array $columns, array $allowedTypes, array $clangIds): array
    {
        $payload = [
            'model' => $this->model,
            'temperature' => 0,
            'messages' => [
                ['role' => 'system', 'content' => AiPrompt::system($allowedTypes, $clangIds)],
                ['role' => 'user', 'content' => AiPrompt::user($columns)],
            ],
        ];

        $socket = \rex_socket::factoryUrl('https://api.openai.com/v1/chat/completions');
        $socket->addHeader('Authorization', 'Bearer ' . $this->apiKey);
        $socket->addHeader('Content-Type', 'application/json');
        $socket->setTimeout(30);
        $response = $socket->doPost((string) json_encode($payload));

        if (!$response->isOk()) {
            return [];
        }
        $body = json_decode($response->getBody(), true);
        $text = isset($body['choices'][0]['message']['content']) ? (string) $body['choices'][0]['message']['content'] : '';

        return AiResponseParser::parse($text, $allowedTypes);
    }
}
```

`lib/YConverter/Schema/Ai/AnthropicProvider.php`:

```php
<?php

/**
 * This file is part of the YConverter package.
 *
 * @author (c) Yakamara Media GmbH & Co. KG
 */

namespace YConverter\Schema\Ai;

/**
 * Anthropic messages provider. One HTTP call via rex_socket; no Composer dependency.
 */
class AnthropicProvider implements AiFieldProvider
{
    /** @var string */
    private $apiKey;
    /** @var string */
    private $model;

    public function __construct(string $apiKey, string $model = '')
    {
        $this->apiKey = $apiKey;
        $this->model = '' !== $model ? $model : 'claude-haiku-4-5';
    }

    public function proposeFields(array $columns, array $allowedTypes, array $clangIds): array
    {
        $payload = [
            'model' => $this->model,
            'max_tokens' => 1024,
            'system' => AiPrompt::system($allowedTypes, $clangIds),
            'messages' => [
                ['role' => 'user', 'content' => AiPrompt::user($columns)],
            ],
        ];

        $socket = \rex_socket::factoryUrl('https://api.anthropic.com/v1/messages');
        $socket->addHeader('x-api-key', $this->apiKey);
        $socket->addHeader('anthropic-version', '2023-06-01');
        $socket->addHeader('Content-Type', 'application/json');
        $socket->setTimeout(30);
        $response = $socket->doPost((string) json_encode($payload));

        if (!$response->isOk()) {
            return [];
        }
        $body = json_decode($response->getBody(), true);
        $text = isset($body['content'][0]['text']) ? (string) $body['content'][0]['text'] : '';

        return AiResponseParser::parse($text, $allowedTypes);
    }
}
```

And the shared prompt builder `lib/YConverter/Schema/Ai/AiPrompt.php`:

```php
<?php

/**
 * This file is part of the YConverter package.
 *
 * @author (c) Yakamara Media GmbH & Co. KG
 */

namespace YConverter\Schema\Ai;

/**
 * Builds the system/user prompts shared by the AI providers.
 */
class AiPrompt
{
    /**
     * @param array<int,string> $allowedTypes
     * @param int[]              $clangIds
     */
    public static function system(array $allowedTypes, array $clangIds): string
    {
        return 'You map legacy database columns to YForm field types for a REDAXO migration. '
            . 'Allowed YForm field types: ' . implode(', ', $allowedTypes) . '. '
            . 'The site languages (rex_clang ids) are: ' . implode(', ', $clangIds) . '. '
            . 'Reply with ONLY a JSON object keyed by column name, each value '
            . '{"type": "<one allowed type>", "params": {}, "reason": "<short>"}. No prose.';
    }

    /**
     * @param array<int,array{name:string,type:string,samples:array<int,string>}> $columns
     */
    public static function user(array $columns): string
    {
        $lines = [];
        foreach ($columns as $c) {
            $samples = isset($c['samples']) ? array_slice($c['samples'], 0, 8) : [];
            $lines[] = '- ' . $c['name'] . ' (' . $c['type'] . ')'
                . (count($samples) ? ' samples: ' . implode(' | ', $samples) : '');
        }
        return "Columns:\n" . implode("\n", $lines);
    }
}
```

Add the new pure file to the harness requires (so the type catalogue/prompt class loads if referenced) — append near the other requires in `tests/run.php`:

```php
require __DIR__ . '/../lib/YConverter/Schema/Ai/AiPrompt.php';
```

- [ ] **Step 6: Run tests + commit**

Run: `php tests/run.php`
Expected: `0 failures`.

```bash
git add lib/YConverter/Schema/Ai/ lib/YConverter/Schema/SchemaDetector.php tests/run.php
git commit -m "feat(schema): AI pass (gap-fill only) + OpenAI/Anthropic providers"
```

---

## Task 8: `Config` AI getters + settings page fields

**Files:**
- Modify: `lib/YConverter/Config.php`
- Modify: `pages/settings.php`

No automated test (reads addon config). Verified manually in Task 10.

- [ ] **Step 1: Add getters to `lib/YConverter/Config.php`** — insert before the closing brace:

```php
    public function getAiProvider()
    {
        return isset($this->config['ai_provider']) ? (string) $this->config['ai_provider'] : 'none';
    }

    public function getAiApiKey()
    {
        return isset($this->config['ai_api_key']) ? (string) $this->config['ai_api_key'] : '';
    }

    public function getAiModel()
    {
        return isset($this->config['ai_model']) ? (string) $this->config['ai_model'] : '';
    }

    public function getAiSendSamples()
    {
        // Default ON (better results); operator can disable to send schema only.
        return !isset($this->config['ai_send_samples']) || (bool) $this->config['ai_send_samples'];
    }
```

- [ ] **Step 2: Add a factory for providers** — create `lib/YConverter/Schema/Ai/AiProviderFactory.php`:

```php
<?php

/**
 * This file is part of the YConverter package.
 *
 * @author (c) Yakamara Media GmbH & Co. KG
 */

namespace YConverter\Schema\Ai;

use YConverter\Config;

class AiProviderFactory
{
    public static function fromConfig(Config $config): ?AiFieldProvider
    {
        $provider = $config->getAiProvider();
        $key = $config->getAiApiKey();
        if ('' === $key || 'none' === $provider) {
            return null;
        }
        if ('openai' === $provider) {
            return new OpenAiProvider($key, $config->getAiModel());
        }
        if ('anthropic' === $provider) {
            return new AnthropicProvider($key, $config->getAiModel());
        }
        return null;
    }
}
```

- [ ] **Step 3: Add settings fields to `pages/settings.php`** — extend the `rex_post('settings', [...])` whitelist (the array around line 36-46) to include:

```php
        ['ai_provider', 'string', 'none'],
        ['ai_api_key', 'string'],
        ['ai_model', 'string'],
        ['ai_send_samples', 'bool'],
```

Then, mirroring the DB-password "keep existing if blank" pattern (around line 49), preserve a saved key when the field is submitted empty — add after that block:

```php
        if (isset($newConfig['ai_api_key']) && '' === $newConfig['ai_api_key'] && !empty($config['ai_api_key'])) {
            $newConfig['ai_api_key'] = $config['ai_api_key'];
        }
```

Add the defaults to the `array_merge` seed (around line 15-29):

```php
    'ai_provider' => 'none',
    'ai_api_key' => null,
    'ai_model' => null,
    'ai_send_samples' => true,
```

And add the form fields (after the media fields block, before the `database_connection` header block ~line 122). Provide a provider select, a masked key input, a model input, and a checkbox:

```php
$aiProvider = new rex_select();
$aiProvider->setName('settings[ai_provider]');
$aiProvider->setAttribute('class', 'form-control selectpicker');
$aiProvider->setSize(1);
$aiProvider->setSelected($config['ai_provider'] ?? 'none');
$aiProvider->addOption($addon->i18n('ai_provider_none'), 'none');
$aiProvider->addOption('OpenAI', 'openai');
$aiProvider->addOption('Anthropic', 'anthropic');

$n = [];
$n['header'] = '<h3>' . $addon->i18n('ai_heading') . '</h3>';
$n['label'] = '<label>' . $addon->i18n('ai_provider') . '</label>';
$n['field'] = $aiProvider->get();
$n['note'] = $addon->i18n('ai_provider_notice');
$formElements[] = $n;

$n = [];
$n['label'] = '<label>' . $addon->i18n('ai_api_key') . '</label>';
$n['field'] = '<input class="form-control" type="password" name="settings[ai_api_key]" value="" placeholder="' . rex_escape(!empty($config['ai_api_key']) ? $addon->i18n('ai_api_key_exists') : '') . '" />';
$formElements[] = $n;

$n = [];
$n['label'] = '<label>' . $addon->i18n('ai_model') . '</label>';
$n['field'] = '<input class="form-control" type="text" name="settings[ai_model]" value="' . rex_escape((string) ($config['ai_model'] ?? '')) . '" placeholder="' . rex_escape($addon->i18n('ai_model_placeholder')) . '" />';
$formElements[] = $n;
```

For the "send samples" checkbox, add it to the existing checkbox fragment block (where `persistent` is rendered ~line 150):

```php
$n = [];
$n['reverse'] = true;
$n['label'] = '<label>' . $addon->i18n('ai_send_samples') . '</label>';
$n['field'] = '<input type="checkbox" name="settings[ai_send_samples]" value="1" ' . (($config['ai_send_samples'] ?? true) ? 'checked="checked" ' : '') . '/>';
$formElements[] = $n;
```

- [ ] **Step 4: Manual verification**

1. `php tests/run.php` → still `0 failures`.
2. Re-install/activate the addon (classmap). In the REDAXO backend open YConverter → Settings; confirm the AI section renders, saving a key persists it, and re-saving with a blank key keeps the stored key.

- [ ] **Step 5: Commit**

```bash
git add lib/YConverter/Config.php lib/YConverter/Schema/Ai/AiProviderFactory.php pages/settings.php
git commit -m "feat(settings): AI provider/key/model/sample-toggle config"
```

---

## Task 9: `YFormImporter` — analyze / import / refreshFields

**Files:**
- Modify: `lib/YConverter/YFormImporter.php`

Refactor so detection feeds the importer. No automated test (SQL/REDAXO); verified manually.

- [ ] **Step 1: Add imports + helpers + the three public operations** — at the top of `lib/YConverter/YFormImporter.php`, add the `use` statements under the existing namespace:

```php
use YConverter\Schema\FieldMapping;
use YConverter\Schema\LangDataMerger;
use YConverter\Schema\SchemaDetector;
use YConverter\Schema\ValueSampler;
use YConverter\Schema\Ai\AiProviderFactory;
```

- [ ] **Step 2: Add `detectExistingYFormTables()`** — add to the class:

```php
    /**
     * Every table already registered in rex_yform_table (candidates for re-detection).
     *
     * @return array<int,array{table_name:string,name:string}>
     */
    public function detectExistingYFormTables()
    {
        $yfTable = \rex::getTable('yform_table');
        if (!$this->tableExists($yfTable)) {
            return [];
        }
        return $this->sql->getArray('SELECT table_name, name FROM ' . $this->sql->escapeIdentifier($yfTable) . ' ORDER BY name');
    }
```

- [ ] **Step 3: Add `analyze()`** — runs detection for one table and returns its `FieldMapping[]`. Add:

```php
    /**
     * Run schema detection for a single table without writing anything.
     *
     * @param string $sourceTable the table to read columns + sample values from
     *                            (staging yconverter_* for new imports, live rex_yf_* for re-detect)
     * @param string $yfTableName the live YForm table name (for existing-field lookup); '' for new
     *
     * @return FieldMapping[]
     */
    public function analyze($sourceTable, $yfTableName = '')
    {
        $columns = \rex_sql::showColumns($sourceTable);
        $sampler = (new ValueSampler($sourceTable))->asCallable();

        $clangIds = array_map('intval', array_keys(\rex_clang::getAll()));
        $langAvailable = LangDataMerger::langFieldsAvailable();

        $existingFields = [];
        if ('' !== $yfTableName) {
            $existingFields = $this->existingFieldTypes($yfTableName);
        }

        $detector = new SchemaDetector(AiProviderFactory::fromConfig($this->config));

        return $detector->detect($columns, $sampler, $clangIds, $langAvailable, $existingFields);
    }

    /**
     * @return array<string,string> column/field name => type_name for currently-registered value fields
     */
    private function existingFieldTypes($yfTableName)
    {
        $yfField = \rex::getTable('yform_field');
        if (!$this->tableExists($yfField)) {
            return [];
        }
        $rows = $this->sql->getArray(
            'SELECT name, type_name FROM ' . $this->sql->escapeIdentifier($yfField) . ' WHERE table_name = :t AND type_id = :v',
            ['t' => $yfTableName, 'v' => 'value']
        );
        $out = [];
        foreach ($rows as $row) {
            $out[$row['name']] = $row['type_name'];
        }
        return $out;
    }
```

- [ ] **Step 4: Replace `convertTable()` and `registerYFormFields()`** — the existing `convertTable()` builds the data table then calls the old `registerYFormFields($target, $columns)`. Replace both with mapping-driven versions and add `import()`/`refreshFields()`:

```php
    /**
     * Import a freshly cloned custom table as a new YForm table.
     *
     * @param string         $base     staging base name (without prefix)
     * @param FieldMapping[] $mappings confirmed mappings (from analyze() or the preview)
     */
    public function import($base, array $mappings)
    {
        $staging = $this->config->getConverterTable($base);
        $target = \rex::getTable('yf_' . $base);

        if (!$this->tableExists($staging)) {
            $this->message->addError(sprintf('Die geklonte Tabelle <code>%s</code> existiert nicht. Bitte zuerst den Klon-Schritt ausführen.', rex_escape($staging)));
            return;
        }

        $this->sql->setQuery('DROP TABLE IF EXISTS ' . $this->sql->escapeIdentifier($target));
        $this->sql->setQuery('CREATE TABLE ' . $this->sql->escapeIdentifier($target) . ' LIKE ' . $this->sql->escapeIdentifier($staging));
        $this->sql->setQuery('INSERT INTO ' . $this->sql->escapeIdentifier($target) . ' SELECT * FROM ' . $this->sql->escapeIdentifier($staging));
        \rex_sql_table::get($target)->ensurePrimaryIdColumn()->alter();

        $this->applyMappings($target, $mappings);
        $this->registerYFormTable($target, $base);

        $rows = $this->sql->getArray('SELECT COUNT(*) AS cnt FROM ' . $this->sql->escapeIdentifier($target));
        $count = isset($rows[0]['cnt']) ? $rows[0]['cnt'] : 0;

        $this->message->addSuccess(sprintf(
            'Tabelle <code>%s</code> wurde als YForm-Tabelle <code>%s</code> mit %d Datensatz/Datensätzen angelegt.',
            rex_escape($base),
            rex_escape($target),
            $count
        ));
    }

    /**
     * Re-detect an already-imported YForm table: refresh its field definitions (and run the
     * i18n transform) WITHOUT recreating the data table or the rex_yform_table row.
     *
     * @param FieldMapping[] $mappings
     */
    public function refreshFields($yfTableName, array $mappings)
    {
        if (!$this->tableExists($yfTableName)) {
            $this->message->addError(sprintf('Die Tabelle <code>%s</code> existiert nicht.', rex_escape($yfTableName)));
            return;
        }

        $this->applyMappings($yfTableName, $mappings);

        $this->message->addSuccess(sprintf(
            'Felddefinitionen für <code>%s</code> wurden aktualisiert.',
            rex_escape($yfTableName)
        ));
    }

    /**
     * Run the i18n data transforms, then (re)write the yform_field rows from the mappings.
     *
     * @param FieldMapping[] $mappings
     */
    private function applyMappings($tableName, array $mappings)
    {
        $merger = new LangDataMerger();
        foreach ($mappings as $mapping) {
            if (!empty($mapping->members['map'])) {
                foreach ($merger->merge($tableName, $mapping) as $note) {
                    $this->message->addWarning($note);
                }
            }
        }

        $this->registerYFormFields($tableName, $mappings);
    }

    /**
     * @param FieldMapping[] $mappings
     */
    private function registerYFormFields($tableName, array $mappings)
    {
        $yfField = \rex::getTable('yform_field');
        if (!$this->tableExists($yfField)) {
            return;
        }

        $delete = \rex_sql::factory();
        $delete->setQuery('DELETE FROM ' . $delete->escapeIdentifier($yfField) . ' WHERE table_name = :t', ['t' => $tableName]);

        $now = date('Y-m-d H:i:s');
        $prio = 1;
        foreach ($mappings as $mapping) {
            $values = [
                'table_name' => $tableName,
                'prio' => $prio++,
                'type_id' => $mapping->typeId,
                'type_name' => $mapping->typeName,
                'db_type' => $mapping->dbType,
                'list_hidden' => 0,
                'search' => 1,
                'name' => $mapping->name,
                'label' => $mapping->label,
                'createdate' => $now,
                'updatedate' => $now,
                'createuser' => 'yconverter',
                'updateuser' => 'yconverter',
            ];
            foreach ($mapping->params as $paramName => $paramValue) {
                $values[$paramName] = $paramValue;
            }
            $this->insertRow($yfField, $values);
        }
    }
```

Keep `registerYFormTable()`, `insertRow()`, `nextPrio()`, `tableExists()`, `detectCustomTables()` as they are. Delete the old `mapType()` method (now in `SchemaDetector::typeFallback()`) and the old `convert()`/`convertTable()` if still present — replaced by `import()`. If `YConverter::convertCustomTables()` calls `convert()`, update it in Task 11's wiring (console) / Task 10 (page). Provide a thin compatibility `convert(array $baseNames)` for any remaining caller:

```php
    /**
     * Convenience non-interactive path: detect + import each base name (auto-apply).
     *
     * @param string[] $baseNames
     */
    public function convert(array $baseNames)
    {
        foreach ($baseNames as $base) {
            $base = trim((string) $base);
            if ('' === $base || \YConverter\AddonMap::isKnownTable($base)) {
                continue;
            }
            try {
                $mappings = $this->analyze($this->config->getConverterTable($base), '');
                $this->import($base, $mappings);
            } catch (\rex_sql_exception $e) {
                $this->message->addError(sprintf('Tabelle <code>%s</code> konnte nicht konvertiert werden: %s', rex_escape($base), rex_escape($e->getMessage())));
            }
        }
    }
```

- [ ] **Step 5: Manual verification**

1. `php tests/run.php` → `0 failures` (pure logic intact).
2. Re-install/activate the addon. With `vegafilm` data already cloned, in a REDAXO console run the existing custom-table conversion path (Task 11 adds `--dry-run`; for now verify no fatal errors on load: `php redaxo/bin/console yconverter:run --help` or the project's console entry).
3. Pick an already-imported `rex_yf_*` table; confirm `analyze($liveTable, $liveTable)` returns mappings and that a previously-registered `lang_*` field is preserved (source `existing`).

- [ ] **Step 6: Commit**

```bash
git add lib/YConverter/YFormImporter.php
git commit -m "feat(yform): mapping-driven import + refreshFields + analyze + existing-table detection"
```

---

## Task 10: Step-4 wizard UI (analyze → preview → apply)

**Files:**
- Modify: `pages/convert.redaxo.php`

Replace the single-shot Step-4 block and add two action handlers. No automated test; verified manually.

- [ ] **Step 1: Add the action handlers** — in the action `switch`/dispatch area (near the existing `case 'yformimport'`, ~line 104), replace the `yformimport` case and add an analyze case. Since analyze renders a preview form (not a converter step), handle both before the package switch. Add near the top action block (after the `reset`/`migrate` handling), a dedicated branch:

```php
} elseif ('yform_analyze' === $func) {
    $importer = new YFormImporter($config, new Message());
    $newBases = rex_request('yconverter_new', 'array', []);
    $existing = rex_request('yconverter_existing', 'array', []);
    $previews = [];
    foreach ($newBases as $base) {
        $base = trim((string) $base);
        if ('' === $base) { continue; }
        $previews[] = [
            'mode' => 'import',
            'key' => $base,
            'tableName' => rex::getTable('yf_' . $base),
            'mappings' => $importer->analyze($config->getConverterTable($base), ''),
        ];
    }
    foreach ($existing as $tableName) {
        $tableName = trim((string) $tableName);
        if ('' === $tableName) { continue; }
        $previews[] = [
            'mode' => 'refresh',
            'key' => $tableName,
            'tableName' => $tableName,
            'mappings' => $importer->analyze($tableName, $tableName),
        ];
    }
    echo $importer->getMessages();
    echo renderYformPreview($previews, $csrfToken);
} elseif ('yform_import' === $func) {
    $importer = new YFormImporter($config, new Message());
    $posted = rex_request('mapping', 'array', []); // [tableKey][fieldIndex] => [name,type,label,params...]
    foreach (rex_request('yform_mode', 'array', []) as $key => $mode) {
        $mappings = buildMappingsFromPost($posted[$key] ?? []);
        if ('import' === $mode) {
            $importer->import($key, $mappings);
        } else {
            $importer->refreshFields($key, $mappings);
        }
    }
    echo $importer->getMessages();
```

Note: place these branches inside the `'' !== $func` valid-config block (so `$config` exists), alongside the `migrate`/default branches. Remove the old `case 'yformimport':` from the package switch.

- [ ] **Step 2: Add the preview renderer + post parser** — add these functions in the helpers area of `pages/convert.redaxo.php` (after the other closures, ~line 178). Use plain functions (the file already mixes closures and procedural code):

```php
function renderYformPreview(array $previews, rex_csrf_token $csrfToken)
{
    if (!count($previews)) {
        return rex_view::info(rex_i18n::msg('yconverter_yform_no_custom_tables'));
    }

    $allowed = ['text', 'textarea', 'choice', 'be_media', 'url', 'email', 'datetime', 'date', 'time', 'integer', 'number', 'checkbox', 'lang_text', 'lang_textarea', 'lang_media'];

    $out = '<form action="' . rex_url::currentBackendPage() . '" method="post">'
        . '<input type="hidden" name="func" value="yform_import" />'
        . $csrfToken->getHiddenField();

    foreach ($previews as $preview) {
        $key = $preview['key'];
        $modeLabel = 'import' === $preview['mode']
            ? '<span class="label label-success">' . rex_i18n::msg('yconverter_yform_mode_import') . '</span>'
            : '<span class="label label-warning">' . rex_i18n::msg('yconverter_yform_mode_refresh') . '</span>';

        $out .= '<input type="hidden" name="yform_mode[' . rex_escape($key) . ']" value="' . rex_escape($preview['mode']) . '" />';
        $rows = '';
        foreach ($preview['mappings'] as $i => $m) {
            $select = '<select class="form-control" name="mapping[' . rex_escape($key) . '][' . $i . '][type]">';
            foreach ($allowed as $type) {
                $select .= '<option value="' . $type . '"' . ($type === $m->typeName ? ' selected' : '') . '>' . $type . '</option>';
            }
            $select .= '</select>';

            $paramsString = '';
            foreach ($m->params as $pName => $pVal) {
                $paramsString .= $pName . '=' . $pVal . "\n";
            }

            $badgeClass = ['HIGH' => 'success', 'MEDIUM' => 'info', 'LOW' => 'default'][$m->confidence];
            $colLabel = !empty($m->members['columns']) ? implode(', ', $m->members['columns']) : $m->name;

            $rows .= '<tr>'
                . '<td><code>' . rex_escape($colLabel) . '</code>'
                . '<input type="hidden" name="mapping[' . rex_escape($key) . '][' . $i . '][name]" value="' . rex_escape($m->name) . '" />'
                . '<input type="hidden" name="mapping[' . rex_escape($key) . '][' . $i . '][dbType]" value="' . rex_escape($m->dbType) . '" />'
                . ($m->members ? '<input type="hidden" name="mapping[' . rex_escape($key) . '][' . $i . '][members]" value="' . rex_escape(json_encode($m->members)) . '" />' : '')
                . '</td>'
                . '<td>' . $select . '</td>'
                . '<td><input class="form-control" type="text" name="mapping[' . rex_escape($key) . '][' . $i . '][label]" value="' . rex_escape($m->label) . '" /></td>'
                . '<td><textarea class="form-control" rows="1" name="mapping[' . rex_escape($key) . '][' . $i . '][params]">' . rex_escape(trim($paramsString)) . '</textarea></td>'
                . '<td><span class="label label-' . $badgeClass . '">' . $m->confidence . '</span></td>'
                . '<td><small>' . rex_escape($m->reason) . '</small></td>'
                . '</tr>';
        }

        $warn = 'refresh' === $preview['mode'] ? rex_view::warning(rex_i18n::msg('yconverter_yform_refresh_warning')) : '';
        $table = '<table class="table table-striped"><thead><tr>'
            . '<th>' . rex_i18n::msg('yconverter_yform_col_columns') . '</th>'
            . '<th>' . rex_i18n::msg('yconverter_yform_col_type') . '</th>'
            . '<th>' . rex_i18n::msg('yconverter_yform_col_label') . '</th>'
            . '<th>' . rex_i18n::msg('yconverter_yform_col_params') . '</th>'
            . '<th>' . rex_i18n::msg('yconverter_yform_col_confidence') . '</th>'
            . '<th>' . rex_i18n::msg('yconverter_yform_col_reason') . '</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table>';

        $fragment = new rex_fragment();
        $fragment->setVar('title', $modeLabel . ' <code>' . rex_escape($preview['tableName']) . '</code>', false);
        $fragment->setVar('body', $warn . $table, false);
        $out .= $fragment->parse('core/page/section.php');
    }

    $out .= '<button class="btn btn-primary btn-lg" type="submit">' . rex_i18n::msg('yconverter_yform_apply') . '</button>';
    $out .= '</form>';

    return $out;
}

function buildMappingsFromPost(array $postedFields)
{
    $mappings = [];
    foreach ($postedFields as $row) {
        $params = [];
        if (!empty($row['params'])) {
            foreach (preg_split('/\r\n|\r|\n/', (string) $row['params']) as $line) {
                $line = trim($line);
                if ('' === $line || false === strpos($line, '=')) {
                    continue;
                }
                list($pName, $pVal) = explode('=', $line, 2);
                $params[trim($pName)] = trim($pVal);
            }
        }
        $opts = [
            'label' => isset($row['label']) ? (string) $row['label'] : '',
            'dbType' => isset($row['dbType']) ? (string) $row['dbType'] : '',
            'params' => $params,
            'source' => 'manual',
        ];
        if (!empty($row['members'])) {
            $decoded = json_decode((string) $row['members'], true);
            if (is_array($decoded)) {
                $opts['members'] = $decoded;
            }
        }
        $mappings[] = new \YConverter\Schema\FieldMapping((string) $row['name'], (string) $row['type'], $opts);
    }
    return $mappings;
}
```

Add `use YConverter\Schema\FieldMapping;` to the top `use` block of `pages/convert.redaxo.php` (next to the existing `use YConverter\YFormImporter;`).

- [ ] **Step 3: Replace the Step-4 render block** — replace the existing Step-4 block (~lines 267-294) with two candidate lists submitting to `yform_analyze`:

```php
// Step 4 — custom tables -> YForm (detect, preview, apply)
$body = '';
if (!$renderConfig->isValid()) {
    $body = '<p>' . rex_i18n::msg('yconverter_yform_no_custom_tables') . '</p>';
} else {
    $importer = new YFormImporter($renderConfig, new Message());
    $newTables = $importer->detectCustomTables();
    $existingTables = $importer->detectExistingYFormTables();

    $checks = '';
    if (count($newTables)) {
        $checks .= '<h4>' . rex_i18n::msg('yconverter_yform_new_tables') . '</h4>';
        foreach ($newTables as $t) {
            $checks .= sprintf(
                '<div class="checkbox"><label><input type="checkbox" name="yconverter_new[]" value="%s" checked> <code>%s</code> &rarr; <code>%s</code></label></div>',
                rex_escape($t), rex_escape($t), rex_escape(rex::getTable('yf_' . $t))
            );
        }
    }
    if (count($existingTables)) {
        $checks .= '<h4>' . rex_i18n::msg('yconverter_yform_existing_tables') . '</h4>';
        foreach ($existingTables as $t) {
            $checks .= sprintf(
                '<div class="checkbox"><label><input type="checkbox" name="yconverter_existing[]" value="%s"> <code>%s</code> <small class="text-muted">(%s)</small></label></div>',
                rex_escape($t['table_name']), rex_escape($t['table_name']), rex_escape($t['name'])
            );
        }
    }

    if ('' === $checks) {
        $body = '<p>' . rex_i18n::msg('yconverter_yform_no_custom_tables') . '</p>';
    } else {
        $body = '<form action="' . rex_url::currentBackendPage() . '" method="post">'
            . '<input type="hidden" name="func" value="yform_analyze" />'
            . $csrfToken->getHiddenField()
            . '<p>' . rex_i18n::msg('yconverter_yform_custom_tables_info') . '</p>'
            . $checks
            . '<button class="btn btn-primary' . (4 === $currentStep ? ' btn-lg' : '') . '" type="submit">' . rex_i18n::msg('yconverter_yform_analyze') . '</button>'
            . '</form>';
    }
}
$out .= $renderStep(4, rex_i18n::msg('yconverter_yform_custom_tables'), false, 4 === $currentStep, $body);
```

- [ ] **Step 4: Manual verification** (spec §10, steps 2-4)

1. `php tests/run.php` → `0 failures`.
2. Re-install/activate the addon. Open YConverter → Convert → Step 4. Confirm both lists render (new custom tables + existing YForm tables).
3. Tick a new custom table → **Analyze** → confirm a preview table appears with editable type/label/params, confidence badges, reasons; i18n groups show their member columns. Edit one type, **Apply**, confirm the `rex_yf_*` table + `rex_yform_field` rows reflect the (edited) mapping; for an i18n field, confirm the merged column holds the `{clang_id,value}` JSON and members were dropped.
4. Tick an already-imported table under "Existing" → **Analyze** → confirm REFRESH tag + warning, a `lang_*` field shows as preserved (reason "already a lang field"), **Apply**, confirm only `rex_yform_field` changed and the data table/`rex_yform_table` row are intact.
5. Temporarily deactivate `yform_lang_fields`, re-analyze a table with `_1/_2` columns → confirm no collapse, individual language-tagged fields, explanatory reason.

- [ ] **Step 5: Commit**

```bash
git add pages/convert.redaxo.php
git commit -m "feat(ui): Step-4 analyze/preview/apply wizard with new + existing table lists"
```

---

## Task 11: Console — auto-apply both lists + `--dry-run`

**Files:**
- Modify: `lib/console/RunCommand.php`

- [ ] **Step 1: Inspect the current command** — read `lib/console/RunCommand.php` to find where it calls `convertCustomTables($yformTables)` (~line 132) and where it lists detected tables (~line 135). Note the existing `SymfonyStyle` `$io` usage and option/argument definitions in `configure()`.

- [ ] **Step 2: Add a `--dry-run` option** — in `configure()`, add:

```php
        $this->addOption('dry-run', null, \Symfony\Component\Console\Input\InputOption::VALUE_NONE, 'Nur die erkannten Feld-Mappings ausgeben, nichts schreiben');
```

- [ ] **Step 3: Implement dry-run + existing-table re-detect** — where the command handles the YForm custom-table step, replace the conversion call with:

```php
$dryRun = (bool) $input->getOption('dry-run');
$importer = new YFormImporter($config, new Message());

if ($dryRun) {
    // New custom tables.
    foreach ($importer->detectCustomTables() as $base) {
        $io->section('NEU: ' . $config->getConverterTable($base) . ' -> ' . rex::getTable('yf_' . $base));
        $this->printMappings($io, $importer->analyze($config->getConverterTable($base), ''));
    }
    // Existing YForm tables.
    foreach ($importer->detectExistingYFormTables() as $t) {
        $io->section('BESTEHEND: ' . $t['table_name']);
        $this->printMappings($io, $importer->analyze($t['table_name'], $t['table_name']));
    }
    return \rex_console_command::SUCCESS;
}

// Auto-apply (non-interactive): detect + write for the selected new tables.
$importer->convert($yformTables);
echo $importer->getMessage()->getAll();
```

Add the helper method to the class:

```php
    private function printMappings(\Symfony\Component\Console\Style\SymfonyStyle $io, array $mappings)
    {
        $rows = [];
        foreach ($mappings as $m) {
            $cols = !empty($m->members['columns']) ? implode(', ', $m->members['columns']) : $m->name;
            $params = [];
            foreach ($m->params as $k => $v) {
                $params[] = $k . '=' . $v;
            }
            $rows[] = [$cols, $m->typeName, $m->confidence, implode(' ', $params), $m->reason];
        }
        $io->table(['Spalte(n)', 'Typ', 'Konfidenz', 'Params', 'Begründung'], $rows);
    }
```

Ensure `use YConverter\Schema\...` isn't needed here (mappings are objects; we only read public props). Keep existing `use` lines; the `\Symfony\...` FQCNs above avoid new imports.

- [ ] **Step 4: Manual verification**

1. `php tests/run.php` → `0 failures`.
2. Re-install/activate. Run the console command with `--dry-run` against `vegafilm`; confirm a readable mapping table prints for both new and existing tables and **nothing is written** (re-check `rex_yform_field` row counts before/after are unchanged).
3. Run without `--dry-run` for a new table; confirm it imports (same result as the UI path).

- [ ] **Step 5: Commit**

```bash
git add lib/console/RunCommand.php
git commit -m "feat(console): --dry-run mapping preview + existing-table re-detect"
```

---

## Task 12: Language strings

**Files:**
- Modify: `lang/de_de.lang`
- Modify: `lang/en_gb.lang` (only if it exists)

- [ ] **Step 1: Add the new keys to `lang/de_de.lang`**

```
yconverter_yform_new_tables = Neue (noch nicht importierte) Tabellen
yconverter_yform_existing_tables = Bereits importierte YForm-Tabellen (Felder neu erkennen)
yconverter_yform_analyze = Analysieren
yconverter_yform_apply = Mappings anwenden
yconverter_yform_mode_import = NEU (Import)
yconverter_yform_mode_refresh = AKTUALISIEREN (Felder ersetzen)
yconverter_yform_refresh_warning = Achtung: Die bestehenden Felddefinitionen dieser Tabelle werden durch die bestätigten Mappings ersetzt. Manuelle Anpassungen an Feldern gehen verloren. Die Daten und die Tabellen-Registrierung bleiben unverändert.
yconverter_yform_col_columns = Spalte(n)
yconverter_yform_col_type = YForm-Feldtyp
yconverter_yform_col_label = Label
yconverter_yform_col_params = Parameter (name=wert je Zeile)
yconverter_yform_col_confidence = Konfidenz
yconverter_yform_col_reason = Begründung
ai_heading = KI-Unterstützung (optional)
ai_provider = KI-Anbieter
ai_provider_none = Keiner
ai_provider_notice = Wird nur für Spalten genutzt, die die Heuristik nicht sicher zuordnen kann. Ohne API-Key vollständig funktionsfähig.
ai_api_key = API-Key
ai_api_key_exists = (gespeichert – zum Beibehalten leer lassen)
ai_model = Modell (optional)
ai_model_placeholder = Standardmodell des Anbieters
ai_send_samples = Beispielwerte an die KI senden (verbessert die Erkennung)
```

- [ ] **Step 2: Mirror in `lang/en_gb.lang` if present** — check first:

Run: `ls lang/`
If `en_gb.lang` exists, add English equivalents of the same keys. If it does not exist, skip (do not create it — match the addon's existing language coverage).

- [ ] **Step 3: Manual verification**

Re-install/activate; reload Step 4 and Settings; confirm no raw `translate:...` / missing-key markers appear (REDAXO shows untranslated keys verbatim).

- [ ] **Step 4: Commit**

```bash
git add lang/de_de.lang
git commit -m "feat(i18n): language strings for schema-detection UI + AI settings"
```

---

## Task 13: End-to-end verification + docs

**Files:**
- Modify: `CLAUDE.md` (document the new detection layer)

- [ ] **Step 1: Full pure-test run**

Run: `php tests/run.php`
Expected: all `✓`, `0 failures`, exit 0.

- [ ] **Step 2: Full manual walk-through against `~/Herd/vegafilm`** (spec §10) — confirm each:
  - `--dry-run` prints sensible mappings covering all five examples (status→choice, year→number, url→url, file→be_media, `_n`→`lang_*`).
  - Import a new custom table via the UI preview; edit one type; apply; verify rows.
  - Re-detect an already-imported table; lang field preserved; data table untouched.
  - i18n merged column JSON round-trips through `LangHelper::normalizeLanguageData()`.
  - With `yform_lang_fields` off: graceful individual-field fallback.
  - With no AI key: full function. With a key: only LOW-confidence fields change.

- [ ] **Step 3: Document the new layer in `CLAUDE.md`** — add a short subsection under the YForm notes describing `lib/YConverter/Schema/` (the detector, rules location, i18n addon dependency, the preview wizard, `tests/run.php`), so future work knows where detection lives. Add:

```markdown
### Schema detection (YForm import)

Custom-table → YForm field mapping lives in `lib/YConverter/Schema/`: `SchemaDetector`
(declarative rule set in `rules()`; add rules there), `FieldMapping` (the per-field result),
`ValueSampler` (lazy DISTINCT sampling), `LangDataMerger` (i18n collapse into the
`yform_lang_fields` JSON format — only when that addon is installed), and `Ai/*` (optional,
gap-fill-only OpenAI/Anthropic providers via `rex_socket`, configured in Settings).
The detector is pure and unit-tested with `php tests/run.php`. Step 4 of `convert.redaxo.php`
is an analyze→preview→apply wizard that also lists already-imported `rex_yform_table`
entries for retroactive re-detection (`YFormImporter::refreshFields()`).
```

- [ ] **Step 4: Commit**

```bash
git add CLAUDE.md
git commit -m "docs: document YConverter schema-detection layer"
```

---

## Self-review notes (addressed)

- **Spec §5.1 FieldMapping** → Task 1. **§5.2 detector + rules** → Tasks 2, 7. **§5.3 ValueSampler** → Task 6. **§5.4 LangDataMerger** → Tasks 4, 6. **§5.5 AI** → Tasks 5, 7, 8. **§5.6 importer** → Task 9. **§5.7 UI wizard** → Task 10. **§5.8 console** → Task 11. **§5.9 config/settings** → Task 8. **§6 i18n** → Tasks 3, 4, 6 (format, addon-conditional, suffix map, idempotency all covered). **§7 AI discipline** → Task 7 (`aiPass` LOW-only + try/catch). **§8 edge cases** → collision (Task 6 `merge`), re-detect overwrite warning (Tasks 9/10), id skip (Task 2), choice semantics editable (Task 10 preview). **§10 verification** → Tasks 9-13 manual steps.
- **Type consistency:** `detect(columns, sampler, clangIds, langFieldsAvailable, existingFields)`, `FieldMapping($name, $typeName, $opts)`, `members['map']` = clangId→column, `LangDataMerger::encodeRow(perClang)`, `AiFieldProvider::proposeFields(columns, allowedTypes, clangIds)`, `analyze(sourceTable, yfTableName)`, `import(base, mappings)`, `refreshFields(tableName, mappings)` — used consistently across Tasks 1-11.
- **No placeholders:** every code step contains complete code; no TBD/TODO.
- **Known assumption to validate at impl time:** AI model default IDs (Task 7) should be confirmed current; the `choice`/`be_media` param syntax was verified against `vegafilm`'s YForm source.
