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
