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
}
