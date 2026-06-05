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

use YConverter\Config;
use YConverter\Message;
use YConverter\Url\UrlProfileImporter;
use YConverter\Url\UrlProfileMapping;

$func = rex_request('func', 'string');
$csrfToken = rex_csrf_token::factory('yconverter');

// --- Actions ---------------------------------------------------------------

if ($func && !$csrfToken->isValid()) {
    echo rex_view::error(rex_i18n::msg('csrf_token_invalid'));
    return;
}
if (!($config = new Config())->isValid()) {
    echo rex_view::error(rex_i18n::msg('yconverter_invalid_config') . '<br />- ' . implode('<br />- ', $config->getValidationErrors()));
    return;
}

if ('url_analyze' === $func) {
    $importer = new UrlProfileImporter($config, new Message());
    if (!$importer->isAvailable()) {
        echo rex_view::warning(rex_i18n::msg('yconverter_url_addon_missing'));
        return;
    }
    echo $importer->getMessage()->getAll();
    echo renderUrlPreview($importer->analyze(), $importer->managerRowCount(), $csrfToken);
    return;
}

if ('url_apply' === $func) {
    $importer = new UrlProfileImporter($config, new Message());
    $importer->apply(buildUrlMappingsFromPost(rex_request('urlmap', 'array', [])));
    echo $importer->getMessage()->getAll();
}

// --- Render: start the analysis --------------------------------------------

$importer = new UrlProfileImporter($config, new Message());

if (!$importer->isAvailable()) {
    $body = '<p>' . rex_i18n::msg('yconverter_url_addon_missing') . '</p>';
} elseif (!count($importer->detectProfiles())) {
    $body = '<p>' . rex_i18n::msg('yconverter_url_no_profiles') . '</p>';
} else {
    $body = '<p>' . rex_i18n::msg('yconverter_url_step_text') . '</p>'
        . '<a class="btn btn-primary btn-lg" href="' . rex_url::currentBackendPage(['func' => 'url_analyze'] + $csrfToken->getUrlParams()) . '">' . rex_i18n::msg('yconverter_url_analyze') . '</a>';
}

$fragment = new rex_fragment();
$fragment->setVar('title', rex_i18n::msg('yconverter_url_step_heading'), false);
$fragment->setVar('body', $body, false);
echo $fragment->parse('core/page/section.php');

// --- Helpers ---------------------------------------------------------------

function renderUrlPreview(array $mappings, $managerRows, rex_csrf_token $csrfToken)
{
    if (!count($mappings)) {
        return rex_view::info(rex_i18n::msg('yconverter_url_no_profiles'));
    }

    $separators = class_exists('Url\\UrlManager') ? Url\UrlManager::getSegmentPartSeparators() : ['/' => '/', '-' => '-', '_' => '_'];

    $out = '';
    if ($managerRows > 0) {
        $out .= rex_view::warning(rex_i18n::msg('yconverter_url_manager_notice', $managerRows));
    }
    $out .= '<form action="' . rex_url::currentBackendPage() . '" method="post">'
        . '<input type="hidden" name="func" value="url_apply" />'
        . $csrfToken->getHiddenField();

    foreach ($mappings as $i => $m) {
        $tp = $m->tableParameters;
        $sepField = '<select class="form-control" name="urlmap[' . $i . '][segment_2_separator]">';
        foreach ($separators as $sepValue => $sepLabel) {
            $selected = (isset($tp['column_segment_part_2_separator']) && $tp['column_segment_part_2_separator'] === $sepValue) ? ' selected' : '';
            $sepField .= '<option value="' . rex_escape($sepValue) . '"' . $selected . '>' . rex_escape($sepLabel) . '</option>';
        }
        $sepField .= '</select>';

        $restriction = isset($tp['restriction_1_column']) && '' !== $tp['restriction_1_column']
            ? rex_escape($tp['restriction_1_column'] . ' ' . (isset($tp['restriction_1_comparison_operator']) ? $tp['restriction_1_comparison_operator'] : '=') . ' ' . (isset($tp['restriction_1_value']) ? $tp['restriction_1_value'] : ''))
            : '<span class="text-muted">—</span>';

        $textInput = function ($field, $value) use ($i) {
            return '<input class="form-control" type="text" name="urlmap[' . $i . '][' . $field . ']" value="' . rex_escape((string) $value) . '" />';
        };

        $rows = '<tr><th style="width:25%">' . rex_i18n::msg('yconverter_url_col_namespace') . '</th><td>' . $textInput('namespace', $m->namespace) . '</td></tr>'
            . '<tr><th>' . rex_i18n::msg('yconverter_url_col_article') . '</th><td>' . $textInput('article_id', $m->articleId) . '</td></tr>'
            . '<tr><th>' . rex_i18n::msg('yconverter_url_col_clang') . '</th><td>' . $textInput('clang_id', $m->clangId) . '</td></tr>'
            . '<tr><th>' . rex_i18n::msg('yconverter_url_col_table') . '</th><td><code>' . rex_escape($m->sourceTable) . '</code> &rarr; ' . $textInput('table', $m->tableName) . '</td></tr>'
            . '<tr><th>' . rex_i18n::msg('yconverter_url_col_id') . '</th><td>' . $textInput('column_id', isset($tp['column_id']) ? $tp['column_id'] : '') . '</td></tr>'
            . '<tr><th>' . rex_i18n::msg('yconverter_url_col_segment') . '</th><td>'
                . $textInput('segment_1', isset($tp['column_segment_part_1']) ? $tp['column_segment_part_1'] : '')
                . ' ' . $sepField . ' '
                . $textInput('segment_2', isset($tp['column_segment_part_2']) ? $tp['column_segment_part_2'] : '')
                . '</td></tr>'
            . '<tr><th>' . rex_i18n::msg('yconverter_url_col_restriction') . '</th><td>' . $restriction
                // Restriction is display-only; carry it through the round-trip as hidden fields.
                . '<input type="hidden" name="urlmap[' . $i . '][restriction_column]" value="' . rex_escape(isset($tp['restriction_1_column']) ? $tp['restriction_1_column'] : '') . '" />'
                . '<input type="hidden" name="urlmap[' . $i . '][restriction_operator]" value="' . rex_escape(isset($tp['restriction_1_comparison_operator']) ? $tp['restriction_1_comparison_operator'] : '=') . '" />'
                . '<input type="hidden" name="urlmap[' . $i . '][restriction_value]" value="' . rex_escape(isset($tp['restriction_1_value']) ? $tp['restriction_1_value'] : '') . '" />'
                . '</td></tr>'
            . '<tr><th>' . rex_i18n::msg('yconverter_url_col_flags') . '</th><td><small>' . rex_escape(implode(' · ', $m->flags)) . '</small></td></tr>'
            . '<tr><th>' . rex_i18n::msg('yconverter_url_col_skip') . '</th><td><label><input type="checkbox" name="urlmap[' . $i . '][remove]" value="1"> ' . rex_i18n::msg('yconverter_url_skip') . '</label></td></tr>';

        $fragment = new rex_fragment();
        $fragment->setVar('title', rex_i18n::msg('yconverter_url_profile') . ' #' . ($i + 1) . ' (clang ' . rex_escape($m->clangId) . ')', false);
        $fragment->setVar('body', '<table class="table table-striped">' . $rows . '</table>', false);
        $out .= $fragment->parse('core/page/section.php');
    }

    $out .= '<div style="margin: 15px 0 40px;">'
        . '<button class="btn btn-primary btn-lg" type="submit">' . rex_i18n::msg('yconverter_url_apply') . '</button>'
        . ' <a class="btn btn-default btn-lg" href="' . rex_url::currentBackendPage() . '">' . rex_i18n::msg('yconverter_yform_cancel') . '</a>'
        . '</div></form>';

    return $out;
}

function buildUrlMappingsFromPost(array $posted)
{
    $mappings = [];
    foreach ($posted as $row) {
        $tableParameters = [
            'column_id' => isset($row['column_id']) ? (string) $row['column_id'] : '',
            'column_clang_id' => '',
            'column_segment_part_1' => isset($row['segment_1']) ? (string) $row['segment_1'] : '',
        ];
        if (isset($row['segment_2']) && '' !== $row['segment_2']) {
            $tableParameters['column_segment_part_2'] = (string) $row['segment_2'];
            $tableParameters['column_segment_part_2_separator'] = isset($row['segment_2_separator']) ? (string) $row['segment_2_separator'] : '-';
        }
        if (isset($row['restriction_column']) && '' !== $row['restriction_column']) {
            $tableParameters['restriction_1_column'] = (string) $row['restriction_column'];
            $tableParameters['restriction_1_comparison_operator'] = isset($row['restriction_operator']) && '' !== $row['restriction_operator'] ? (string) $row['restriction_operator'] : '=';
            $tableParameters['restriction_1_value'] = isset($row['restriction_value']) ? (string) $row['restriction_value'] : '';
        }
        $mappings[] = new UrlProfileMapping([
            'namespace' => isset($row['namespace']) ? (string) $row['namespace'] : '',
            'articleId' => (int) (isset($row['article_id']) ? $row['article_id'] : 0),
            'clangId' => (int) (isset($row['clang_id']) ? $row['clang_id'] : 1),
            'tableName' => isset($row['table']) ? (string) $row['table'] : '',
            'tableParameters' => $tableParameters,
            'remove' => !empty($row['remove']),
        ]);
    }
    return $mappings;
}
