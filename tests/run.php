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

echo "\n{$GLOBALS['__tests']} checks, {$GLOBALS['__fail']} failures\n";
exit($GLOBALS['__fail'] ? 1 : 0);
