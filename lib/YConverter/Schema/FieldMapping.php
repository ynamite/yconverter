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
