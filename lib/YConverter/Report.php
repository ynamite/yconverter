<?php

/**
 * This file is part of the YConverter package.
 *
 * @author (c) Yakamara Media GmbH & Co. KG
 * @author Thomas Blum <thomas.blum@yakamara.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace YConverter;

/**
 * Collects the manual-review hints (warnings/errors) of a migration run into a markdown
 * file, so the operator can work through them afterwards instead of being overwhelmed by
 * the full output on screen.
 */
class Report
{
    public static function path()
    {
        return \rex_addon::get('yconverter')->getDataPath('migration-report.md');
    }

    public static function relativePath()
    {
        return \rex_path::relative(self::path());
    }

    public static function reset()
    {
        \rex_file::put(self::path(), "# YConverter – Migrationsbericht\n\nHinweise zur manuellen Nachbearbeitung nach der Datenmigration (z. B. anzupassender Modul-/Template-Code, fehlende Tabellen/Spalten).\n");
    }

    /**
     * Appends the warnings/errors of one message block (e.g. a migration step) as a
     * markdown section. Routine success/info messages are omitted.
     */
    public static function appendFromMessage(Message $message, $section)
    {
        $lines = [];
        foreach ($message->getEntries() as $entry) {
            if ('warning' === $entry['type'] || 'error' === $entry['type']) {
                $lines[] = '- '.self::htmlToText($entry['value']);
            }
        }
        if (!$lines) {
            return;
        }

        $markdown = "\n## ".$section."\n\n".implode("\n", $lines)."\n";
        \rex_file::put(self::path(), (string) \rex_file::get(self::path(), '').$markdown);
    }

    private static function htmlToText($html)
    {
        $text = preg_replace('@<br\s*/?>@i', ' ', (string) $html);
        $text = preg_replace('@</(dd|dt|dl|p|div|li)>@i', ' ', $text);
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5);

        return trim((string) preg_replace('/\s+/', ' ', $text));
    }
}
