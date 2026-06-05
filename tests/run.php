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
eq($m->listHidden, 0, 'listHidden defaults to 0');
eq($m->search, 1, 'search defaults to 1');
$m3 = new FieldMapping('x', 'text', ['listHidden' => 1, 'search' => 0]);
eq($m3->listHidden, 1, 'listHidden override (preserved from existing def)');
eq($m3->search, 0, 'search override');

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

// url -> text with type="url" attribute (YForm has no real "url" field type)
$r = $detect->detect([['name' => 'website', 'type' => 'varchar(255)']], sampler([]), [1], false);
eq($r[0]->typeName, 'text', 'website -> text');
eq($r[0]->params['type'], 'url', 'website gets type=url attribute');
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

require __DIR__ . '/../lib/YConverter/Schema/Ai/AiPrompt.php';
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

echo "\nSchemaDetector — AI pass\n";

// Fake provider: proposes textarea for everything it's asked about, and records what it saw.
class FakeAiProvider implements YConverter\Schema\Ai\AiFieldProvider
{
    public $seen = [];
    public $sawSamples = [];
    public function proposeFields(array $columns, array $allowedTypes, array $clangIds): array
    {
        $out = [];
        foreach ($columns as $c) {
            $this->seen[] = $c['name'];
            $this->sawSamples[] = $c['samples'];
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
eq($byName['website']->typeName, 'text', 'HIGH match not overridden by AI');
eq($byName['mystery']->typeName, 'textarea', 'LOW field replaced by AI proposal');
eq($byName['mystery']->source, 'ai', 'AI source tagged');
eq($byName['mystery']->confidence, FieldMapping::MEDIUM, 'AI proposal is MEDIUM confidence');

// aiSendSamples=false -> the provider must receive no sample values.
$fakeNoSamples = new FakeAiProvider();
$detectNoSamples = new SchemaDetector($fakeNoSamples, false);
$detectNoSamples->detect([['name' => 'mystery', 'type' => 'varchar(255)']], sampler(['mystery' => ['a', 'b', 'c']]), [1], false);
eq($fakeNoSamples->sawSamples, [[]], 'aiSendSamples=false sends empty samples');

// aiSendSamples=true (default) -> samples are forwarded.
$fakeSamples = new FakeAiProvider();
$detectSamples = new SchemaDetector($fakeSamples, true);
$detectSamples->detect([['name' => 'mystery', 'type' => 'varchar(255)']], sampler(['mystery' => ['a', 'b']]), [1], false);
eq($fakeSamples->sawSamples, [['a', 'b']], 'aiSendSamples=true forwards sampled values');

echo "\nSchemaDetector — unix timestamp -> datestamp\n";
$detect = new SchemaDetector();
foreach (['createdate', 'updatedate', 'created_at', 'pubdate', 'timestamp'] as $col) {
    $r = $detect->detect([['name' => $col, 'type' => 'int(11)']], sampler([]), [1], false);
    eq($r[0]->typeName, 'datestamp', "$col (int) -> datestamp");
    eq($r[0]->dbType, 'datetime', "$col output db_type datetime");
    eq($r[0]->transform, 'unixToDatetime', "$col flagged for FROM_UNIXTIME");
}
// year must NOT be treated as a timestamp.
$r = $detect->detect([['name' => 'year', 'type' => 'int(11)']], sampler([]), [1], false);
eq($r[0]->typeName, 'number', 'year stays number, not datestamp');
// a non-integer createdate is not converted.
$r = $detect->detect([['name' => 'createdate', 'type' => 'varchar(20)']], sampler([]), [1], false);
eq($r[0]->transform, '', 'varchar createdate not flagged for conversion');

echo "\nFieldMapping::splitParamsForColumns\n";
$split = FieldMapping::splitParamsForColumns(
    ['choices' => 'a=1', 'multiple' => 1, 'class' => 'tiny-editor', 'data-profile' => 'massif'],
    ['choices', 'multiple', 'attributes']
);
eq($split['columns'], ['choices' => 'a=1', 'multiple' => 1], 'real columns kept direct');
eq($split['attributes'], ['class' => 'tiny-editor', 'data-profile' => 'massif'], 'non-columns folded to attributes');

$split = FieldMapping::splitParamsForColumns(
    ['attributes' => '{"class":"x"}', 'data-profile' => 'massif'],
    ['attributes']
);
eq($split['attributes'], ['class' => 'x', 'data-profile' => 'massif'], 'explicit attributes JSON merged with folded keys');

$split = FieldMapping::splitParamsForColumns(['multiple' => 1], ['choices', 'multiple', 'attributes']);
eq($split['attributes'], [], 'no attributes when all params are columns');

echo "\nSchemaDetector — be_user + extended type catalogue\n";
$detect = new SchemaDetector();
$r = $detect->detect([['name' => 'author', 'type' => 'varchar(191)']], sampler([]), [1], false);
eq($r[0]->typeName, 'be_user', 'author -> be_user');
$r = $detect->detect([['name' => 'editor', 'type' => 'int(11)']], sampler([]), [1], false);
eq($r[0]->typeName, 'be_user', 'editor (int) -> be_user');
$r = $detect->detect([['name' => 'username', 'type' => 'varchar(191)']], sampler([]), [1], false);
ok('be_user' !== $r[0]->typeName, 'username is NOT be_user');

$types = SchemaDetector::allowedTypes();
foreach (['be_user', 'be_link', 'email', 'custom_link', 'custom_link_multi', 'imagelist', 'color_swatch', 'medialist', 'linklist'] as $t) {
    ok(in_array($t, $types, true), "allowedTypes contains $t");
}

echo "\nSchemaDetector — email + color_swatch + availability guard\n";
$detect = new SchemaDetector();
$r = $detect->detect([['name' => 'email', 'type' => 'varchar(191)']], sampler([]), [1], false);
eq($r[0]->typeName, 'email', 'email -> email field (YForm core)');
$r = $detect->detect([['name' => 'contact_mail', 'type' => 'varchar(191)']], sampler([]), [1], false);
eq($r[0]->typeName, 'email', 'contact_mail -> email');
$r = $detect->detect([['name' => 'farbe', 'type' => 'varchar(20)']], sampler([]), [1], false);
eq($r[0]->typeName, 'color_swatch', 'farbe -> color_swatch (mform available by default)');
$r = $detect->detect([['name' => 'bg_color', 'type' => 'varchar(20)']], sampler([]), [1], false);
eq($r[0]->typeName, 'color_swatch', 'bg_color -> color_swatch');

// Availability guard: when color_swatch's addon is absent, the rule downgrades to text.
$coreOnly = ['text', 'textarea', 'choice', 'be_media', 'be_user', 'be_link', 'email', 'datetime', 'date', 'time', 'datestamp', 'integer', 'number', 'checkbox'];
$guarded = new SchemaDetector(null, true, $coreOnly);
$r = $guarded->detect([['name' => 'farbe', 'type' => 'varchar(20)']], sampler([]), [1], false);
eq($r[0]->typeName, 'text', 'color_swatch unavailable -> text fallback');
eq($r[0]->confidence, FieldMapping::LOW, 'downgrade is LOW confidence');
// email stays (core, always available)
$r = $guarded->detect([['name' => 'email', 'type' => 'varchar(191)']], sampler([]), [1], false);
eq($r[0]->typeName, 'email', 'email stays available in core-only set');

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

echo "\n{$GLOBALS['__tests']} checks, {$GLOBALS['__fail']} failures\n";
exit($GLOBALS['__fail'] ? 1 : 0);
