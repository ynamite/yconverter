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
