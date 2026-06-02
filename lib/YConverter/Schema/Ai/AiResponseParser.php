<?php

/**
 * This file is part of the YConverter package.
 *
 * @author (c) Yakamara Media GmbH & Co. KG
 */

namespace YConverter\Schema\Ai;

/**
 * Pure parser/validator for an AI provider's JSON reply. Tolerant: extracts the first
 * JSON object even if the model wrapped it in prose, and silently drops any field whose
 * proposed type is not in the allowed catalogue.
 */
class AiResponseParser
{
    /**
     * @param string            $raw     model reply text
     * @param array<int,string> $allowed allowed YForm type names
     *
     * @return array<string,array{typeName:string,params:array,reason:string}>
     */
    public static function parse(string $raw, array $allowed): array
    {
        $json = self::extractJson($raw);
        if ('' === $json) {
            return [];
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            return [];
        }

        $allowedSet = array_flip($allowed);
        $out = [];
        foreach ($data as $name => $spec) {
            if (!is_string($name) || !is_array($spec)) {
                continue;
            }
            $type = isset($spec['type']) ? (string) $spec['type'] : (isset($spec['typeName']) ? (string) $spec['typeName'] : '');
            if ('' === $type || !isset($allowedSet[$type])) {
                continue;
            }
            $out[$name] = [
                'typeName' => $type,
                'params' => (isset($spec['params']) && is_array($spec['params'])) ? $spec['params'] : [],
                'reason' => isset($spec['reason']) ? (string) $spec['reason'] : 'KI-Vorschlag',
            ];
        }

        return $out;
    }

    private static function extractJson(string $raw): string
    {
        $raw = trim($raw);
        if ('' === $raw) {
            return '';
        }
        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');
        if (false === $start || false === $end || $end < $start) {
            return '';
        }
        return substr($raw, $start, $end - $start + 1);
    }
}
