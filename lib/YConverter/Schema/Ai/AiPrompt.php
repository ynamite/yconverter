<?php

/**
 * This file is part of the YConverter package.
 *
 * @author (c) Yakamara Media GmbH & Co. KG
 */

namespace YConverter\Schema\Ai;

/**
 * Builds the system/user prompts shared by the AI providers.
 */
class AiPrompt
{
    /**
     * @param array<int,string> $allowedTypes
     * @param int[]              $clangIds
     */
    public static function system(array $allowedTypes, array $clangIds): string
    {
        return 'You map legacy database columns to YForm field types for a REDAXO migration. '
            . 'Allowed YForm field types: ' . implode(', ', $allowedTypes) . '. '
            . 'The site languages (rex_clang ids) are: ' . implode(', ', $clangIds) . '. '
            . 'Reply with ONLY a JSON object keyed by column name, each value '
            . '{"type": "<one allowed type>", "params": {}, "reason": "<short>"}. No prose.';
    }

    /**
     * @param array<int,array{name:string,type:string,samples:array<int,string>}> $columns
     */
    public static function user(array $columns): string
    {
        $lines = [];
        foreach ($columns as $c) {
            $samples = isset($c['samples']) ? array_slice($c['samples'], 0, 8) : [];
            $lines[] = '- ' . $c['name'] . ' (' . $c['type'] . ')'
                . (count($samples) ? ' samples: ' . implode(' | ', $samples) : '');
        }
        return "Columns:\n" . implode("\n", $lines);
    }
}
