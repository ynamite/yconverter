<?php

/**
 * This file is part of the YConverter package.
 *
 * @author (c) Yakamara Media GmbH & Co. KG
 */

namespace YConverter\Schema;

/**
 * Collapses R4 per-language columns (name_<suffix>) into a single yform_lang_fields
 * JSON column. The DB transform lives in merge() (a later task); encodeRow() is the pure
 * encoder that reproduces yform_lang_fields' storage format exactly.
 */
class LangDataMerger
{
    /**
     * @param array<int,mixed> $perClang clangId => raw value
     */
    public static function encodeRow(array $perClang): string
    {
        $normalized = [];
        foreach ($perClang as $clangId => $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $value = trim((string) $value);
            if ('' === $value) {
                continue;
            }
            $normalized[] = ['clang_id' => (int) $clangId, 'value' => $value];
        }

        $json = json_encode($normalized, JSON_UNESCAPED_UNICODE);

        return false === $json ? '[]' : $json;
    }

    public static function langFieldsAvailable(): bool
    {
        return \rex_addon::get('yform_lang_fields')->isAvailable();
    }

    /**
     * Collapse an i18n group in $tableName into a single JSON column, in place.
     * Idempotent and safe-ordered: adds + populates the new column before dropping the
     * source members. Assumes $mapping->members = ['map' => clangId=>column, ...].
     *
     * @return string[] human-readable notes (warnings/info) about what happened
     */
    public function merge(string $tableName, FieldMapping $mapping): array
    {
        $notes = [];
        if (empty($mapping->members['map'])) {
            return $notes;
        }

        $sql = \rex_sql::factory();
        $sql->setDebug(false);
        $tableEscaped = $sql->escapeIdentifier($tableName);

        $existing = array_column(\rex_sql::showColumns($tableName), 'name');
        $target = $mapping->name;
        $map = $mapping->members['map']; // clangId => sourceColumn

        $membersPresent = [];
        foreach ($map as $clangId => $column) {
            if (in_array($column, $existing, true)) {
                $membersPresent[(int) $clangId] = $column;
            }
        }

        // Idempotency: already collapsed (target exists, no members left) -> nothing to do.
        if (in_array($target, $existing, true) && 0 === count($membersPresent)) {
            return $notes;
        }

        // Name collision: a real column already equals the prefix but members still exist.
        if (in_array($target, $existing, true) && count($membersPresent) > 0) {
            $notes[] = sprintf('i18n-Gruppe "%s" übersprungen: Zielspalte existiert bereits.', $target);
            return $notes;
        }

        if (0 === count($membersPresent)) {
            return $notes; // nothing to merge
        }

        // 1. Add the JSON column (text).
        \rex_sql_table::get($tableName)
            ->ensureColumn(new \rex_sql_column($target, 'text'))
            ->alter();

        // 2. Populate row by row. Any SQL error here throws before the destructive drop below.
        $idRows = $sql->getArray('SELECT id FROM ' . $tableEscaped, [], \PDO::FETCH_NUM);
        foreach ($idRows as $idRow) {
            $id = $idRow[0];
            $select = \rex_sql::factory();
            $select->setDebug(false);
            $cols = [];
            foreach ($membersPresent as $column) {
                $cols[] = $select->escapeIdentifier($column);
            }
            $select->setQuery(
                'SELECT ' . implode(', ', $cols) . ' FROM ' . $tableEscaped . ' WHERE id = :id',
                ['id' => $id]
            );

            $perClang = [];
            foreach ($membersPresent as $clangId => $column) {
                $perClang[$clangId] = $select->getValue($column);
            }

            $update = \rex_sql::factory();
            $update->setDebug(false);
            $update->setTable($tableName);
            $update->setWhere(['id' => $id]);
            $update->setValue($target, self::encodeRow($perClang));
            $update->update();
        }

        // 3. Drop the source member columns (auto-commits; only after a successful populate).
        $table = \rex_sql_table::get($tableName);
        foreach ($membersPresent as $column) {
            $table->removeColumn($column);
        }
        $table->alter();

        $notes[] = sprintf('i18n-Gruppe nach "%s" zusammengeführt (%d Sprachspalten).', $target, count($membersPresent));

        return $notes;
    }
}
