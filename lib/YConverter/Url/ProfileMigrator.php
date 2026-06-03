<?php

/**
 * This file is part of the YConverter package.
 *
 * @author (c) Yakamara Media GmbH & Co. KG
 */

namespace YConverter\Url;

/**
 * Pure translation of a seo42 url_control_generate row into a draft url-addon profile.
 * No REDAXO calls: live clang ids and the resolved target table are injected.
 */
class ProfileMigrator
{
    /**
     * @param array       $oldRow        url_control_generate row
     * @param int[]       $clangIds      live rex_clang ids
     * @param string|null $resolvedTable R5 table the data lives in, or null
     */
    public static function migrate(array $oldRow, array $clangIds, $resolvedTable)
    {
        $table = (string) (isset($oldRow['table']) ? $oldRow['table'] : '');

        $params = @unserialize((string) (isset($oldRow['table_parameters']) ? $oldRow['table_parameters'] : ''));
        if (!is_array($params)) {
            $params = [];
        }
        $active = (isset($params[$table]) && is_array($params[$table])) ? $params[$table] : [];

        $get = static function ($suffix) use ($active, $table) {
            $key = $table . '_' . $suffix;
            return isset($active[$key]) ? (string) $active[$key] : '';
        };

        $tableParameters = [
            'column_id' => $get('id'),
            'column_clang_id' => '',
            'column_segment_part_1' => $get('name'),
        ];
        if ('' !== $get('name_2')) {
            $tableParameters['column_segment_part_2'] = $get('name_2');
            $tableParameters['column_segment_part_2_separator'] = '-';
        }
        if ('' !== $get('restriction_field')) {
            $tableParameters['restriction_1_column'] = $get('restriction_field');
            $tableParameters['restriction_1_comparison_operator'] = '' !== $get('restriction_operator') ? $get('restriction_operator') : '=';
            $tableParameters['restriction_1_value'] = $get('restriction_value');
        }

        $flags = [];

        list($clangId, $clangFlag) = self::mapClang((int) (isset($oldRow['clang']) ? $oldRow['clang'] : 0), $clangIds);
        if (null !== $clangFlag) {
            $flags[] = $clangFlag;
        }

        $tableName = is_string($resolvedTable) ? $resolvedTable : '';
        if ('' === $tableName) {
            $flags[] = sprintf('Quelltabelle "%s" konnte keiner R5-Tabelle zugeordnet werden — bitte wählen.', $table);
        }
        if ('' === $get('name')) {
            $flags[] = 'Kein URL-Segment (Name-Spalte) erkannt — bitte prüfen.';
        }
        $flags[] = 'Artikel-ID prüfen (kann sich gegenüber REDAXO 4 unterscheiden).';
        $flags[] = 'Namespace anpassen.';

        return new UrlProfileMapping([
            'sourceTable' => $table,
            'oldId' => (int) (isset($oldRow['id']) ? $oldRow['id'] : 0),
            'namespace' => self::namespaceFromTable('' !== $tableName ? $tableName : $table),
            'articleId' => (int) (isset($oldRow['article_id']) ? $oldRow['article_id'] : 0),
            'clangId' => $clangId,
            'dbId' => 1,
            'tableName' => $tableName,
            'tableParameters' => $tableParameters,
            'createdate' => self::timestamp(isset($oldRow['createdate']) ? $oldRow['createdate'] : null),
            'updatedate' => self::timestamp(isset($oldRow['updatedate']) ? $oldRow['updatedate'] : null),
            'createuser' => (string) (isset($oldRow['createuser']) ? $oldRow['createuser'] : 'yconverter'),
            'updateuser' => (string) (isset($oldRow['updateuser']) ? $oldRow['updateuser'] : 'yconverter'),
            'flags' => $flags,
        ]);
    }

    /**
     * @param int[] $clangIds
     *
     * @return array{0:int,1:?string} [clangId, flag|null]
     */
    private static function mapClang($oldClang, array $clangIds)
    {
        if (in_array($oldClang + 1, $clangIds, true)) {
            return [$oldClang + 1, null]; // R4 0-based -> R5 1-based
        }
        if (in_array($oldClang, $clangIds, true)) {
            return [$oldClang, null];
        }
        $fallback = $clangIds ? min($clangIds) : 1;
        return [$fallback, sprintf('Sprache (clang %d) konnte nicht zugeordnet werden — auf %d gesetzt.', $oldClang, $fallback)];
    }

    private static function namespaceFromTable($table)
    {
        $base = preg_replace('/^rex_/', '', (string) $table);
        $base = preg_replace('/^yf_/', '', $base);
        return '' !== $base ? $base : 'profile';
    }

    private static function timestamp($value)
    {
        $int = (int) $value;
        return $int > 0 ? date('Y-m-d H:i:s', $int) : '';
    }
}
