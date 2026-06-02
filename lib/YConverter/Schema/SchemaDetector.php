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

        // i18n grouping pass added in a later task:
        $mappings = $this->groupI18n($mappings, $clangIds, $langFieldsAvailable);

        // AI pass added in a later task:
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
     * i18n grouping — implemented in a later task. For now a no-op pass-through.
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
     * AI refinement — implemented in a later task. For now a no-op pass-through.
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
