<?php
// Zero-dependency test runner for YConverter's pure logic.
// Run: php tests/run.php

error_reporting(E_ALL);

require __DIR__ . '/../lib/YConverter/Schema/FieldMapping.php';
require __DIR__ . '/../lib/YConverter/Schema/Ai/AiFieldProvider.php';

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

echo "\n{$GLOBALS['__tests']} checks, {$GLOBALS['__fail']} failures\n";
exit($GLOBALS['__fail'] ? 1 : 0);
