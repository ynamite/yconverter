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
    /** @var string optional data transform to run at apply, e.g. 'unixToDatetime' */
    public $transform = '';
    /** @var int rex_yform_field.list_hidden (0 = shown in the list) */
    public $listHidden = 0;
    /** @var int rex_yform_field.search (1 = searchable) */
    public $search = 1;

    /**
     * @param array{label?:string,typeId?:string,dbType?:string,params?:array,confidence?:string,reason?:string,source?:string,members?:array,transform?:string,listHidden?:int,search?:int} $opts
     */
    public function __construct(string $name, string $typeName, array $opts = [])
    {
        $this->name = $name;
        $this->typeName = $typeName;
        $this->label = isset($opts['label']) ? $opts['label'] : self::prettify($name);

        foreach (['typeId', 'dbType', 'params', 'confidence', 'reason', 'source', 'members', 'transform', 'listHidden', 'search'] as $key) {
            if (array_key_exists($key, $opts)) {
                $this->$key = $opts[$key];
            }
        }
    }

    public static function prettify(string $name): string
    {
        return ucfirst(trim(str_replace('_', ' ', $name)));
    }

    /**
     * Splits field params into those that map to a real rex_yform_field column and those that
     * are HTML attributes to be folded into the field's `attributes` JSON. An explicit
     * `attributes` param (already JSON, or an assoc array) seeds the attribute set.
     *
     * @param array<string,mixed> $params
     * @param array<int,string>   $realColumns existing rex_yform_field column names
     *
     * @return array{columns:array<string,mixed>,attributes:array<string,mixed>}
     */
    public static function splitParamsForColumns(array $params, array $realColumns): array
    {
        $columnSet = array_flip($realColumns);
        $columns = [];
        $attributes = [];

        foreach ($params as $key => $value) {
            if ('attributes' === $key) {
                if (is_array($value)) {
                    $attributes = array_merge($attributes, $value);
                } elseif (is_string($value) && '' !== $value) {
                    $decoded = json_decode($value, true);
                    if (is_array($decoded)) {
                        $attributes = array_merge($attributes, $decoded);
                    }
                }
                continue;
            }
            if (isset($columnSet[$key])) {
                $columns[$key] = $value;
            } else {
                $attributes[$key] = $value;
            }
        }

        return ['columns' => $columns, 'attributes' => $attributes];
    }
}
