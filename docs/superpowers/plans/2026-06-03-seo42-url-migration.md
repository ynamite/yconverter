# seo42 URL-Control → `url` Addon Migration — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Migrate seo42's DB-dataset URL generation (staged `yconverter_url_control_generate`) into REDAXO 5 `url`-addon profiles (`rex_url_generator_profile`), via a new Step-5 analyze→preview→apply wizard, then let the `url` addon (re)generate the URLs.

**Architecture:** A pure `YConverter\Url\ProfileMigrator` deserializes the old `table_parameters` blob and produces a `UrlProfileMapping` per old row (clang +1, columns → new JSON keys, flags for the parts that need operator review). A REDAXO-coupled `UrlProfileImporter` resolves the old table → `rex_yf_<base>`, runs the migrator, writes profile rows on confirm, and rebuilds via the `url` addon's own APIs (`Url\Cache`, `Url\UrlManagerSql`, `Url\Profile`). A Step-5 wizard in `convert.redaxo.php` reuses the Step-4 preview pattern.

**Tech Stack:** PHP (REDAXO 5 addon, classmap autoload, no Composer), the `url` addon 2.3.0 (`Url\*` classes), the YForm-migrated `rex_yf_*` tables. Tests: `php tests/run.php` (zero-dependency).

---

## Testing approach (read first)

Same as the rest of this addon: no Composer/PHPUnit. The **pure** logic (`UrlProfileMapping`, `ProfileMigrator`) is built test-first against `php tests/run.php`. The **REDAXO-coupled** pieces (`UrlProfileImporter`, the Step-5 page, lang) are verified with `php -l` + a read-only live `analyze` against the 3 real `yconverter_url_control_generate` rows in `~/Herd/vegafilm`. **Do NOT run `apply()` against the live vegafilm DB** during development — it would (correctly) replace yconverter-created profiles; leave a real apply to the operator on a disposable copy.

After adding classes under `lib/`, re-install/re-activate the addon or clear the REDAXO cache (classmap autoload).

## File structure

- *New (pure):* `lib/YConverter/Url/UrlProfileMapping.php`, `lib/YConverter/Url/ProfileMigrator.php`
- *New (coupled):* `lib/YConverter/Url/UrlProfileImporter.php`
- *Modified:* `pages/convert.redaxo.php` (Step 5 + `url_analyze`/`url_apply` actions + `renderUrlPreview`/`buildUrlMappingsFromPost`), `lang/de_de.lang`, `tests/run.php`, `README.md`, `CHANGELOG.md`, `CLAUDE.md`

---

## Task 1: `UrlProfileMapping` value object

**Files:**
- Create: `lib/YConverter/Url/UrlProfileMapping.php`
- Modify: `tests/run.php` (append)

- [ ] **Step 1: Write the failing test** — append to `tests/run.php` BEFORE the final summary `echo`:

```php
require __DIR__ . '/../lib/YConverter/Url/UrlProfileMapping.php';

use YConverter\Url\UrlProfileMapping;

echo "\nUrlProfileMapping\n";
$m = new UrlProfileMapping(['sourceTable' => 'rex_x', 'articleId' => 7, 'clangId' => 2, 'flags' => ['a']]);
eq($m->sourceTable, 'rex_x', 'sourceTable set');
eq($m->articleId, 7, 'articleId set');
eq($m->clangId, 2, 'clangId set');
eq($m->dbId, 1, 'dbId defaults to 1');
eq($m->tableName, '', 'tableName defaults empty');
eq($m->tableParameters, [], 'tableParameters defaults empty');
eq($m->remove, false, 'remove defaults false');
eq($m->flags, ['a'], 'flags set');
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/run.php`
Expected: fatal error — cannot open `UrlProfileMapping.php`.

- [ ] **Step 3: Write minimal implementation** — create `lib/YConverter/Url/UrlProfileMapping.php`:

```php
<?php

/**
 * This file is part of the YConverter package.
 *
 * @author (c) Yakamara Media GmbH & Co. KG
 */

namespace YConverter\Url;

/**
 * One draft rex_url_generator_profile derived from a seo42 url_control_generate row,
 * plus operator-review flags. Edited in the Step-5 preview before being written.
 */
class UrlProfileMapping
{
    /** @var string old source table (display) */
    public $sourceTable = '';
    /** @var int old url_control_generate id */
    public $oldId = 0;
    /** @var string */
    public $namespace = '';
    /** @var int */
    public $articleId = 0;
    /** @var int */
    public $clangId = 1;
    /** @var int REDAXO DB connection id of the target table */
    public $dbId = 1;
    /** @var string resolved R5 table (e.g. rex_yf_vegafilm); '' if unresolved */
    public $tableName = '';
    /** @var array<string,scalar> the rex_url_generator_profile.table_parameters JSON */
    public $tableParameters = [];
    /** @var string Y-m-d H:i:s */
    public $createdate = '';
    /** @var string Y-m-d H:i:s */
    public $updatedate = '';
    /** @var string */
    public $createuser = 'yconverter';
    /** @var string */
    public $updateuser = 'yconverter';
    /** @var bool operator chose to skip this profile */
    public $remove = false;
    /** @var string[] human-readable "please verify" notes */
    public $flags = [];

    public function __construct(array $opts = [])
    {
        foreach ($opts as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/run.php`
Expected: all `✓`, `0 failures`, exit 0.

- [ ] **Step 5: Commit**

```bash
git add lib/YConverter/Url/UrlProfileMapping.php tests/run.php
git commit -m "feat(url): add UrlProfileMapping value object"
```

---

## Task 2: `ProfileMigrator` (pure)

**Files:**
- Create: `lib/YConverter/Url/ProfileMigrator.php`
- Modify: `tests/run.php` (append)

`migrate(array $oldRow, array $clangIds, ?string $resolvedTable): UrlProfileMapping` — pure. `$oldRow` has keys `id`, `article_id`, `clang`, `table`, `table_parameters` (PHP-serialized string), `createdate`, `updatedate`, `createuser`, `updateuser`.

- [ ] **Step 1: Write the failing test** — append to `tests/run.php` before the final summary:

```php
require __DIR__ . '/../lib/YConverter/Url/ProfileMigrator.php';

use YConverter\Url\ProfileMigrator;

echo "\nProfileMigrator\n";

// Reference case: rex_vegafilm, clang 0, name=title, name_2=year, id=id, no restriction.
$blob = serialize([
    'rex_other' => ['rex_other_name' => 'x', 'rex_other_id' => 'x'],
    'rex_vegafilm' => [
        'rex_vegafilm_name' => 'title',
        'rex_vegafilm_name_2' => 'year',
        'rex_vegafilm_id' => 'id',
        'rex_vegafilm_restriction_field' => '',
        'rex_vegafilm_restriction_operator' => '=',
        'rex_vegafilm_restriction_value' => '',
    ],
]);
$row = ['id' => 1, 'article_id' => 7, 'clang' => 0, 'table' => 'rex_vegafilm', 'table_parameters' => $blob, 'createdate' => 1700000000, 'updatedate' => 1700000000, 'createuser' => 'admin', 'updateuser' => 'admin'];
$m = ProfileMigrator::migrate($row, [1, 2, 3], 'rex_yf_vegafilm');
eq($m->tableParameters['column_id'], 'id', 'column_id from <t>_id');
eq($m->tableParameters['column_segment_part_1'], 'title', 'segment_1 from <t>_name');
eq($m->tableParameters['column_segment_part_2'], 'year', 'segment_2 from <t>_name_2');
eq($m->tableParameters['column_segment_part_2_separator'], '-', 'default separator -');
eq($m->clangId, 1, 'clang 0 -> 1');
eq($m->articleId, 7, 'article copied');
eq($m->tableName, 'rex_yf_vegafilm', 'resolved table used');
eq($m->namespace, 'vegafilm', 'namespace from table base');
ok(!isset($m->tableParameters['restriction_1_column']), 'no restriction when empty');
ok(false !== strpos(implode(' ', $m->flags), 'Artikel-ID'), 'article flagged');

// Restriction present + no name_2 + clang 1 -> 2.
$blob2 = serialize(['t' => ['t_name' => 'n', 't_name_2' => '', 't_id' => 'pid', 't_restriction_field' => 'status', 't_restriction_operator' => '=', 't_restriction_value' => '1']]);
$m2 = ProfileMigrator::migrate(['id' => 2, 'article_id' => 5, 'clang' => 1, 'table' => 't', 'table_parameters' => $blob2], [1, 2, 3], 'rex_yf_t');
eq($m2->tableParameters['restriction_1_column'], 'status', 'restriction column');
eq($m2->tableParameters['restriction_1_value'], '1', 'restriction value');
ok(!isset($m2->tableParameters['column_segment_part_2']), 'no segment_2 when name_2 empty');
eq($m2->clangId, 2, 'clang 1 -> 2');

// Unresolved table -> empty + flag.
$m3 = ProfileMigrator::migrate(['id' => 3, 'article_id' => 1, 'clang' => 0, 'table' => 'rex_foo', 'table_parameters' => serialize(['rex_foo' => ['rex_foo_name' => 'n', 'rex_foo_id' => 'id']])], [1, 2, 3], null);
eq($m3->tableName, '', 'unresolved -> empty tableName');
ok(false !== strpos(implode(' ', $m3->flags), 'zugeordnet'), 'unresolved flagged');

// Clang not alignable -> fallback to min().
$m4 = ProfileMigrator::migrate(['id' => 4, 'article_id' => 1, 'clang' => 9, 'table' => 't', 'table_parameters' => serialize(['t' => ['t_name' => 'n', 't_id' => 'id']])], [1, 2, 3], 'rex_yf_t');
eq($m4->clangId, 1, 'clang 9 -> fallback min(1)');
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/run.php`
Expected: fatal error — cannot open `ProfileMigrator.php`.

- [ ] **Step 3: Write minimal implementation** — create `lib/YConverter/Url/ProfileMigrator.php`:

```php
<?php

/**
 * This file is part of the YConverter package.
 *
 * @author (c) Yakamara Media GmbH & Co. KG
 */

namespace YConverter\Url;

/**
 * Pure translation of a seo42 url_control_generate row into a draft url-addon profile.
 * No REDAXO calls: live clang ids and the resolved target table are injected.
 */
class ProfileMigrator
{
    /**
     * @param array               $oldRow        url_control_generate row
     * @param int[]                $clangIds      live rex_clang ids
     * @param string|null          $resolvedTable R5 table the data lives in, or null
     */
    public static function migrate(array $oldRow, array $clangIds, $resolvedTable)
    {
        $table = (string) (isset($oldRow['table']) ? $oldRow['table'] : '');

        $params = @unserialize((string) (isset($oldRow['table_parameters']) ? $oldRow['table_parameters'] : ''));
        if (!is_array($params)) {
            $params = [];
        }
        $active = (isset($params[$table]) && is_array($params[$table])) ? $params[$table] : [];

        $get = static function ($suffix) use ($active, $table) {
            $key = $table . '_' . $suffix;
            return isset($active[$key]) ? (string) $active[$key] : '';
        };

        $tableParameters = [
            'column_id' => $get('id'),
            'column_clang_id' => '',
            'column_segment_part_1' => $get('name'),
        ];
        if ('' !== $get('name_2')) {
            $tableParameters['column_segment_part_2'] = $get('name_2');
            $tableParameters['column_segment_part_2_separator'] = '-';
        }
        if ('' !== $get('restriction_field')) {
            $tableParameters['restriction_1_column'] = $get('restriction_field');
            $tableParameters['restriction_1_comparison_operator'] = '' !== $get('restriction_operator') ? $get('restriction_operator') : '=';
            $tableParameters['restriction_1_value'] = $get('restriction_value');
        }

        $flags = [];

        list($clangId, $clangFlag) = self::mapClang((int) (isset($oldRow['clang']) ? $oldRow['clang'] : 0), $clangIds);
        if (null !== $clangFlag) {
            $flags[] = $clangFlag;
        }

        $tableName = is_string($resolvedTable) ? $resolvedTable : '';
        if ('' === $tableName) {
            $flags[] = sprintf('Quelltabelle "%s" konnte keiner R5-Tabelle zugeordnet werden — bitte wählen.', $table);
        }
        if ('' === $get('name')) {
            $flags[] = 'Kein URL-Segment (Name-Spalte) erkannt — bitte prüfen.';
        }
        $flags[] = 'Artikel-ID prüfen (kann sich gegenüber REDAXO 4 unterscheiden).';
        $flags[] = 'Namespace anpassen.';

        return new UrlProfileMapping([
            'sourceTable' => $table,
            'oldId' => (int) (isset($oldRow['id']) ? $oldRow['id'] : 0),
            'namespace' => self::namespaceFromTable('' !== $tableName ? $tableName : $table),
            'articleId' => (int) (isset($oldRow['article_id']) ? $oldRow['article_id'] : 0),
            'clangId' => $clangId,
            'dbId' => 1,
            'tableName' => $tableName,
            'tableParameters' => $tableParameters,
            'createdate' => self::timestamp(isset($oldRow['createdate']) ? $oldRow['createdate'] : null),
            'updatedate' => self::timestamp(isset($oldRow['updatedate']) ? $oldRow['updatedate'] : null),
            'createuser' => (string) (isset($oldRow['createuser']) ? $oldRow['createuser'] : 'yconverter'),
            'updateuser' => (string) (isset($oldRow['updateuser']) ? $oldRow['updateuser'] : 'yconverter'),
            'flags' => $flags,
        ]);
    }

    /**
     * @param int[] $clangIds
     *
     * @return array{0:int,1:?string} [clangId, flag|null]
     */
    private static function mapClang($oldClang, array $clangIds)
    {
        if (in_array($oldClang + 1, $clangIds, true)) {
            return [$oldClang + 1, null]; // R4 0-based -> R5 1-based
        }
        if (in_array($oldClang, $clangIds, true)) {
            return [$oldClang, null];
        }
        $fallback = $clangIds ? min($clangIds) : 1;
        return [$fallback, sprintf('Sprache (clang %d) konnte nicht zugeordnet werden — auf %d gesetzt.', $oldClang, $fallback)];
    }

    private static function namespaceFromTable($table)
    {
        $base = preg_replace('/^rex_/', '', (string) $table);
        $base = preg_replace('/^yf_/', '', $base);
        return '' !== $base ? $base : 'profile';
    }

    private static function timestamp($value)
    {
        $int = (int) $value;
        return $int > 0 ? date('Y-m-d H:i:s', $int) : '';
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/run.php`
Expected: all `✓`, `0 failures`, exit 0.

- [ ] **Step 5: Commit**

```bash
git add lib/YConverter/Url/ProfileMigrator.php tests/run.php
git commit -m "feat(url): pure ProfileMigrator (seo42 row -> draft url profile)"
```

---

## Task 3: `UrlProfileImporter` (REDAXO-coupled)

**Files:**
- Create: `lib/YConverter/Url/UrlProfileImporter.php`

No automated test (uses `\rex_*` and `\Url\*`). Verify with `php -l` + the read-only live `analyze` in Task 6.

- [ ] **Step 1: Create `lib/YConverter/Url/UrlProfileImporter.php`**

```php
<?php

/**
 * This file is part of the YConverter package.
 *
 * @author (c) Yakamara Media GmbH & Co. KG
 */

namespace YConverter\Url;

use YConverter\Config;
use YConverter\Message;

/**
 * Reads the staged seo42 url_control_generate profiles, turns them into draft url-addon
 * profiles (via ProfileMigrator), and — on apply — writes rex_url_generator_profile rows and
 * rebuilds the url addon's profile cache + generated URLs using the addon's own APIs.
 */
class UrlProfileImporter
{
    private $config;
    private $message;
    private $sql;

    public function __construct(Config $config, Message $message)
    {
        $this->sql = \rex_sql::factory();
        $this->sql->setDebug(false);
        $this->config = $config;
        $this->message = $message;
    }

    public function getMessage()
    {
        return $this->message;
    }

    public function isAvailable()
    {
        return \rex_addon::get('url')->isAvailable() && class_exists('Url\\Profile') && class_exists('Url\\Database');
    }

    /**
     * @return array<int,array<string,mixed>> staged url_control_generate rows
     */
    public function detectProfiles()
    {
        $staging = $this->config->getConverterTable('url_control_generate');
        if (!$this->tableExists($staging)) {
            return [];
        }
        return $this->sql->getArray('SELECT * FROM ' . $this->sql->escapeIdentifier($staging) . ' ORDER BY id');
    }

    public function managerRowCount()
    {
        $staging = $this->config->getConverterTable('url_control_manager');
        if (!$this->tableExists($staging)) {
            return 0;
        }
        $rows = $this->sql->getArray('SELECT COUNT(*) AS c FROM ' . $this->sql->escapeIdentifier($staging));
        return isset($rows[0]['c']) ? (int) $rows[0]['c'] : 0;
    }

    /**
     * Resolve an old source table to the R5 table its data now lives in.
     */
    public function resolveTable($oldTable)
    {
        $prefix = $this->config->getOutdatedTablePrefix();
        $base = (0 === strpos($oldTable, $prefix)) ? substr($oldTable, strlen($prefix)) : $oldTable;

        $yf = \rex::getTable('yf_' . $base);
        if ($this->tableExists($yf)) {
            return $yf;
        }
        $direct = \rex::getTable($base);
        if ($this->tableExists($direct)) {
            return $direct;
        }
        return null;
    }

    /**
     * @return UrlProfileMapping[]
     */
    public function analyze()
    {
        $clangIds = array_map('intval', array_keys(\rex_clang::getAll()));
        $mappings = [];
        foreach ($this->detectProfiles() as $row) {
            $resolved = $this->resolveTable((string) $row['table']);
            $mappings[] = ProfileMigrator::migrate($row, $clangIds, $resolved);
        }
        return $mappings;
    }

    /**
     * Write confirmed profiles and rebuild the url addon's profiles + URLs.
     *
     * @param UrlProfileMapping[] $mappings
     */
    public function apply(array $mappings)
    {
        if (!$this->isAvailable()) {
            $this->message->addError('Das URL-Addon ist nicht installiert/aktiviert — es können keine Profile angelegt werden.');
            return;
        }

        $profileTable = \rex::getTable('url_generator_profile');
        $written = 0;

        foreach ($mappings as $mapping) {
            if ($mapping->remove || '' === $mapping->tableName) {
                continue;
            }
            $tableNameValue = \Url\Database::merge($mapping->dbId, $mapping->tableName);

            // Replace only our own previously-migrated profiles; never touch operator-made ones.
            $delete = \rex_sql::factory();
            $delete->setQuery(
                'DELETE FROM ' . $delete->escapeIdentifier($profileTable) . ' WHERE createuser = :u AND table_name = :t AND clang_id = :c',
                ['u' => 'yconverter', 't' => $tableNameValue, 'c' => $mapping->clangId]
            );

            $now = date('Y-m-d H:i:s');
            $insert = \rex_sql::factory();
            $insert->setTable($profileTable);
            $insert->setValue('namespace', $mapping->namespace);
            $insert->setValue('article_id', $mapping->articleId);
            $insert->setValue('clang_id', $mapping->clangId);
            $insert->setValue('ep_pre_save_called', 0);
            $insert->setValue('table_name', $tableNameValue);
            $insert->setValue('table_parameters', (string) json_encode($mapping->tableParameters));
            foreach (['relation_1_table_name', 'relation_2_table_name', 'relation_3_table_name'] as $col) {
                $insert->setValue($col, '');
            }
            foreach (['relation_1_table_parameters', 'relation_2_table_parameters', 'relation_3_table_parameters'] as $col) {
                $insert->setValue($col, '');
            }
            $insert->setValue('createdate', '' !== $mapping->createdate ? $mapping->createdate : $now);
            $insert->setValue('updatedate', '' !== $mapping->updatedate ? $mapping->updatedate : $now);
            $insert->setValue('createuser', 'yconverter');
            $insert->setValue('updateuser', 'yconverter');
            $insert->insert();
            ++$written;
        }

        // Rebuild profile cache + regenerate URLs (the recipe used by the url addon's own page).
        \Url\Cache::deleteProfiles();
        \Url\UrlManagerSql::deleteAll();
        foreach (\Url\Profile::getAll() as $profile) {
            $profile->buildUrls();
        }

        $urlCount = \rex_sql::factory()->getArray('SELECT COUNT(*) AS c FROM ' . $this->sql->escapeIdentifier(\rex::getTable('url_generator_url')));
        $urls = isset($urlCount[0]['c']) ? (int) $urlCount[0]['c'] : 0;

        $this->message->addSuccess(sprintf('%d URL-Profil(e) angelegt; %d URL(s) generiert.', $written, $urls));
    }

    private function tableExists($table)
    {
        try {
            return \count(\rex_sql::showColumns($table)) > 0;
        } catch (\rex_sql_exception $e) {
            return false;
        }
    }
}
```

- [ ] **Step 2: Verify**

Run: `php -l lib/YConverter/Url/UrlProfileImporter.php` → "No syntax errors detected".
Run: `php tests/run.php` → still `0 failures` (pure tests unaffected).

- [ ] **Step 3: Commit**

```bash
git add lib/YConverter/Url/UrlProfileImporter.php
git commit -m "feat(url): UrlProfileImporter (detect/resolve/analyze/apply + addon rebuild)"
```

---

## Task 4: Step-5 wizard UI

**Files:**
- Modify: `pages/convert.redaxo.php`

No automated test. READ `pages/convert.redaxo.php` fully first — reuse its existing structures (`$csrfToken`, the `elseif ('' !== $func)` dispatch chain where `$config` is valid, the `$renderStep` closure, `$renderConfig`, `$currentStep`, and the `rex_url::currentBackendPage()` mapping-mode `return` pattern used by `yform_analyze`).

- [ ] **Step 1: Add `use`** at the top, next to `use YConverter\YFormImporter;`:

```php
use YConverter\Url\UrlProfileImporter;
use YConverter\Url\UrlProfileMapping;
```

- [ ] **Step 2: Add the two action branches** in the valid-config `elseif` chain (right after the `yform_import` branch, before the `else { … switch … }`):

```php
} elseif ('url_analyze' === $func) {
    $importer = new UrlProfileImporter($config, new Message());
    if (!$importer->isAvailable()) {
        echo rex_view::warning(rex_i18n::msg('yconverter_url_addon_missing'));
        return;
    }
    echo $importer->getMessage()->getAll();
    echo renderUrlPreview($importer->analyze(), $importer->managerRowCount(), $csrfToken);
    return;
} elseif ('url_apply' === $func) {
    $importer = new UrlProfileImporter($config, new Message());
    $importer->apply(buildUrlMappingsFromPost(rex_request('urlmap', 'array', [])));
    echo $importer->getMessage()->getAll();
```

- [ ] **Step 3: Add the render + post-parse functions** in the helpers area (next to `renderYformPreview`/`buildMappingsFromPost`), as top-level functions:

```php
function renderUrlPreview(array $mappings, $managerRows, rex_csrf_token $csrfToken)
{
    if (!count($mappings)) {
        return rex_view::info(rex_i18n::msg('yconverter_url_no_profiles'));
    }

    $separators = class_exists('Url\\UrlManager') ? Url\UrlManager::getSegmentPartSeparators() : ['/' => '/', '-' => '-', '_' => '_'];

    $out = '';
    if ($managerRows > 0) {
        $out .= rex_view::warning(rex_i18n::msg('yconverter_url_manager_notice', $managerRows));
    }
    $out .= '<form action="' . rex_url::currentBackendPage() . '" method="post">'
        . '<input type="hidden" name="func" value="url_apply" />'
        . $csrfToken->getHiddenField();

    foreach ($mappings as $i => $m) {
        $tp = $m->tableParameters;
        $sepField = '<select class="form-control" name="urlmap[' . $i . '][segment_2_separator]">';
        foreach ($separators as $sepValue => $sepLabel) {
            $selected = (isset($tp['column_segment_part_2_separator']) && $tp['column_segment_part_2_separator'] === $sepValue) ? ' selected' : '';
            $sepField .= '<option value="' . rex_escape($sepValue) . '"' . $selected . '>' . rex_escape($sepLabel) . '</option>';
        }
        $sepField .= '</select>';

        $restriction = isset($tp['restriction_1_column']) && '' !== $tp['restriction_1_column']
            ? rex_escape($tp['restriction_1_column'] . ' ' . (isset($tp['restriction_1_comparison_operator']) ? $tp['restriction_1_comparison_operator'] : '=') . ' ' . (isset($tp['restriction_1_value']) ? $tp['restriction_1_value'] : ''))
            : '<span class="text-muted">—</span>';

        $textInput = function ($field, $value) use ($i) {
            return '<input class="form-control" type="text" name="urlmap[' . $i . '][' . $field . ']" value="' . rex_escape((string) $value) . '" />';
        };

        $rows = '<tr><th style="width:25%">' . rex_i18n::msg('yconverter_url_col_namespace') . '</th><td>' . $textInput('namespace', $m->namespace) . '</td></tr>'
            . '<tr><th>' . rex_i18n::msg('yconverter_url_col_article') . '</th><td>' . $textInput('article_id', $m->articleId) . '</td></tr>'
            . '<tr><th>' . rex_i18n::msg('yconverter_url_col_clang') . '</th><td>' . $textInput('clang_id', $m->clangId) . '</td></tr>'
            . '<tr><th>' . rex_i18n::msg('yconverter_url_col_table') . '</th><td><code>' . rex_escape($m->sourceTable) . '</code> &rarr; ' . $textInput('table', $m->tableName) . '</td></tr>'
            . '<tr><th>' . rex_i18n::msg('yconverter_url_col_id') . '</th><td>' . $textInput('column_id', isset($tp['column_id']) ? $tp['column_id'] : '') . '</td></tr>'
            . '<tr><th>' . rex_i18n::msg('yconverter_url_col_segment') . '</th><td>'
                . $textInput('segment_1', isset($tp['column_segment_part_1']) ? $tp['column_segment_part_1'] : '')
                . ' ' . $sepField . ' '
                . $textInput('segment_2', isset($tp['column_segment_part_2']) ? $tp['column_segment_part_2'] : '')
                . '</td></tr>'
            . '<tr><th>' . rex_i18n::msg('yconverter_url_col_restriction') . '</th><td>' . $restriction . '</td></tr>'
            . '<tr><th>' . rex_i18n::msg('yconverter_url_col_flags') . '</th><td><small>' . rex_escape(implode(' · ', $m->flags)) . '</small></td></tr>'
            . '<tr><th>' . rex_i18n::msg('yconverter_url_col_skip') . '</th><td><label><input type="checkbox" name="urlmap[' . $i . '][remove]" value="1"> ' . rex_i18n::msg('yconverter_url_skip') . '</label></td></tr>';

        $fragment = new rex_fragment();
        $fragment->setVar('title', rex_i18n::msg('yconverter_url_profile') . ' #' . ($i + 1) . ' (clang ' . rex_escape($m->clangId) . ')', false);
        $fragment->setVar('body', '<table class="table table-striped">' . $rows . '</table>', false);
        $out .= $fragment->parse('core/page/section.php');
    }

    $out .= '<div style="margin: 15px 0 40px;">'
        . '<button class="btn btn-primary btn-lg" type="submit">' . rex_i18n::msg('yconverter_url_apply') . '</button>'
        . ' <a class="btn btn-default btn-lg" href="' . rex_url::currentBackendPage() . '">' . rex_i18n::msg('yconverter_yform_cancel') . '</a>'
        . '</div></form>';

    return $out;
}

function buildUrlMappingsFromPost(array $posted)
{
    $mappings = [];
    foreach ($posted as $row) {
        $tableParameters = [
            'column_id' => isset($row['column_id']) ? (string) $row['column_id'] : '',
            'column_clang_id' => '',
            'column_segment_part_1' => isset($row['segment_1']) ? (string) $row['segment_1'] : '',
        ];
        if (isset($row['segment_2']) && '' !== $row['segment_2']) {
            $tableParameters['column_segment_part_2'] = (string) $row['segment_2'];
            $tableParameters['column_segment_part_2_separator'] = isset($row['segment_2_separator']) ? (string) $row['segment_2_separator'] : '-';
        }
        $mappings[] = new UrlProfileMapping([
            'namespace' => isset($row['namespace']) ? (string) $row['namespace'] : '',
            'articleId' => (int) (isset($row['article_id']) ? $row['article_id'] : 0),
            'clangId' => (int) (isset($row['clang_id']) ? $row['clang_id'] : 1),
            'tableName' => isset($row['table']) ? (string) $row['table'] : '',
            'tableParameters' => $tableParameters,
            'remove' => !empty($row['remove']),
        ]);
    }
    return $mappings;
}
```

- [ ] **Step 4: Add the Step-5 render block** after the existing `$renderStep(4, …)` block (before the reset link / closing render):

```php
// Step 5 — seo42 URL-Control -> url addon (optional)
$urlImporter = new UrlProfileImporter($renderConfig, new Message());
if ($renderConfig->isValid() && $urlImporter->isAvailable() && count($urlImporter->detectProfiles())) {
    $body = '<p>' . rex_i18n::msg('yconverter_url_step_text') . '</p>'
        . '<a class="btn btn-primary" href="' . $url('url_analyze', '') . '">' . rex_i18n::msg('yconverter_url_analyze') . '</a>';
    $out .= $renderStep(5, rex_i18n::msg('yconverter_url_step_heading'), false, false, $body);
}
```

- [ ] **Step 5: Verify**

Run: `php -l pages/convert.redaxo.php` → no syntax errors.
Run: `grep -n "url_analyze\|url_apply\|renderUrlPreview\|buildUrlMappingsFromPost" pages/convert.redaxo.php` → all present.
Run: `php tests/run.php` → `0 failures`.

- [ ] **Step 6: Commit**

```bash
git add pages/convert.redaxo.php
git commit -m "feat(ui): Step-5 seo42 -> url profile analyze/preview/apply wizard"
```

---

## Task 5: Language strings

**Files:**
- Modify: `lang/de_de.lang`

- [ ] **Step 1: Append the keys** (after the existing `yconverter_yform_*` block):

```
yconverter_url_step_heading = seo42 → URL-Profile
yconverter_url_step_text = Erkennt seo42-URL-Generierungsprofile (url_control) und legt daraus Profile für das URL-Addon an. Namespace, Artikel-ID und Tabelle bitte in der Vorschau prüfen.
yconverter_url_analyze = Analysieren
yconverter_url_apply = Profile anlegen
yconverter_url_no_profiles = Es wurden keine seo42-URL-Profile (url_control_generate) gefunden.
yconverter_url_addon_missing = Das URL-Addon ist nicht installiert/aktiviert. Bitte zuerst installieren.
yconverter_url_manager_notice = Hinweis: {0} Eintrag/Einträge in url_control_manager (manuelle URL-Methoden) werden nicht automatisch migriert und müssen ggf. manuell nachgebaut werden.
yconverter_url_profile = URL-Profil
yconverter_url_col_namespace = Namespace
yconverter_url_col_article = Artikel-ID
yconverter_url_col_clang = Sprache (clang_id)
yconverter_url_col_table = Tabelle
yconverter_url_col_id = ID-Spalte
yconverter_url_col_segment = URL-Segment (Teil 1 / Trenner / Teil 2)
yconverter_url_col_restriction = Einschränkung
yconverter_url_col_flags = Zu prüfen
yconverter_url_col_skip = Überspringen
yconverter_url_skip = Dieses Profil nicht anlegen
```

- [ ] **Step 2: Verify keys resolve**

```bash
for k in $(grep -oE "yconverter_url_[a-z_]+" pages/convert.redaxo.php | sort -u); do grep -q "^$k = " lang/de_de.lang && echo "OK $k" || echo "MISS $k"; done
```
Expected: every key `OK`.

- [ ] **Step 3: Commit**

```bash
git add lang/de_de.lang
git commit -m "feat(i18n): strings for the seo42 -> url migration step"
```

---

## Task 6: Docs + end-to-end verification

**Files:**
- Modify: `README.md`, `CHANGELOG.md`, `CLAUDE.md`

- [ ] **Step 1: Full pure-test run**

Run: `php tests/run.php`
Expected: all `✓`, `0 failures`, exit 0.

- [ ] **Step 2: Read-only live check against `~/Herd/vegafilm`** (the addon is symlinked there). Add a temporary console print is unnecessary — instead confirm `analyze` output by re-installing the addon (cache clear) and opening *YConverter → Converter → Step 5 → Analysieren* in the backend, OR compare via this throwaway check that the 3 staged rows produce 3 mappings with `column_segment_part_1 = title`, `clangId` 1/2/3, `tableName = rex_yf_vegafilm`, `namespace = vegafilm` (flagged), `articleId = 7` (flagged). **Do not click "Profile anlegen" against the live DB** (it would replace yconverter-created profiles). Confirm the derived columns/clang match your hand-built `movie` profiles; only `namespace`/`article_id` differ (the flagged fields).

- [ ] **Step 3: Document in `README.md`** — add a section after the YForm section:

```markdown
## seo42 URL-Control → URL-Addon

War auf der alten Seite seo42s URL-Generierung aktiv (`rex_url_control_generate`), bietet
Schritt 5 an, daraus Profile für das [`url`-Addon](https://github.com/FriendsOfREDAXO/url)
(`rex_url_generator_profile`) zu erzeugen. Ableitbar sind: ein Profil je altem Eintrag,
`clang` +1 (R4 0-basiert → R5 1-basiert), ID-/Segment-Spalten und einfache Einschränkungen
sowie die Zieltabelle (alte Tabelle → `rex_yf_<name>`). Namespace und Artikel-ID werden
vorbelegt, aber zur Prüfung markiert. Nach „Profile anlegen" werden über die Addon-eigenen
Funktionen die Profile registriert und die URLs neu generiert. `rex_url_control_manager`
(manuelle URL-Methoden) wird nur als Hinweis gemeldet, nicht automatisch migriert.
Voraussetzung: das `url`-Addon ist installiert.
```

- [ ] **Step 4: Document in `CHANGELOG.md`** — add to the `## [Unveröffentlicht]` section (create it above the latest released version if absent), under `### Hinzugefügt`:

```
- Migration der seo42-URL-Generierung (`url_control_generate`) in Profile des `url`-Addons
  (`rex_url_generator_profile`): neuer Schritt 5 mit Analyse → Vorschau → Anlegen, inkl.
  clang-Verschiebung (+1), Tabellen-Zuordnung auf `rex_yf_<name>`, ID-/Segment-Spalten und
  einfacher Einschränkung; anschließende URL-Neugenerierung über die Addon-eigenen APIs.
  `url_control_manager` wird als manueller Nacharbeitspunkt gemeldet.
```

- [ ] **Step 5: Document in `CLAUDE.md`** — add a short note under the Schema-detection subsection:

```markdown
### seo42 → url migration

`lib/YConverter/Url/` migrates seo42 URL-control profiles into the `url` addon:
`ProfileMigrator` (pure: deserialize old `table_parameters`, map columns + clang, flag
review items), `UrlProfileImporter` (resolve old table → `rex_yf_*`, write
`rex_url_generator_profile`, rebuild via `Url\Cache`/`Url\UrlManagerSql`/`Url\Profile`), and
Step 5 of `convert.redaxo.php`. Addon-conditional (`rex_addon::get('url')`).
```

- [ ] **Step 6: Commit**

```bash
git add README.md CHANGELOG.md CLAUDE.md
git commit -m "docs: document the seo42 -> url migration step"
```

---

## Self-review notes (addressed)

- **Spec coverage:** §4.1 UrlProfileMapping → Task 1; §4.2 ProfileMigrator (unserialize, column map, clang +1/fallback, namespace, flags) → Task 2; §4.3 UrlProfileImporter (isAvailable/detect/managerRowCount/resolveTable/analyze/apply + rebuild + createuser dedup) → Task 3; §4.4 Step-5 wizard (analyze/apply/preview/skip/manager notice) → Task 4; lang → Task 5; §7 verification + docs → Task 6. §4.5 console `--dry-run` was optional and is intentionally omitted (YAGNI for now).
- **Placeholder scan:** every step has complete code; no TBD/TODO.
- **Type consistency:** `ProfileMigrator::migrate($oldRow, $clangIds, $resolvedTable)`, `UrlProfileMapping` props (`sourceTable/namespace/articleId/clangId/dbId/tableName/tableParameters/remove/flags`), `UrlProfileImporter::{isAvailable,detectProfiles,managerRowCount,resolveTable,analyze,apply,getMessage}`, the POST shape `urlmap[i][namespace|article_id|clang_id|table|column_id|segment_1|segment_2|segment_2_separator|remove]`, and `Url\Database::merge` / `Url\Cache::deleteProfiles` / `Url\UrlManagerSql::deleteAll` / `Url\Profile::getAll`+`buildUrls` are used consistently across Tasks 1–4.
- **Known impl-time check:** confirm `ep_pre_save_called = 0` is accepted (the profile form's default) — verified against `pages/generator.profiles.php`.
