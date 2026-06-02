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
