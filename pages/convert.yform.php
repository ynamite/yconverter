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
use YConverter\Schema\FieldMapping;
use YConverter\Schema\FieldTypes;
use YConverter\YFormImporter;

$func = rex_request('func', 'string');
$csrfToken = rex_csrf_token::factory('yconverter');

// --- Actions ---------------------------------------------------------------

if ($func && !$csrfToken->isValid()) {
    echo rex_view::error(rex_i18n::msg('csrf_token_invalid'));
    return;
}
if (!($config = new Config())->isValid()) {
    echo rex_view::error(
        rex_i18n::msg('yconverter_invalid_config') . '<br />- ' . implode('<br />- ', $config->getValidationErrors())
        . '<br /><a href="' . rex_url::backendPage('yconverter/settings') . '">' . rex_i18n::msg('yconverter_open_settings') . '</a>'
    );
    return;
}

if ('yform_analyze' === $func) {
    $importer = new YFormImporter($config, new Message());
    $previews = [];
    foreach (rex_request('yconverter_new', 'array', []) as $base) {
        $base = trim((string) $base);
        if ('' === $base) { continue; }
        $previews[] = [
            'mode' => 'import',
            'key' => $base,
            'tableName' => rex::getTable('yf_' . $base),
            'mappings' => $importer->analyze($config->getConverterTable($base), ''),
        ];
    }
    foreach (rex_request('yconverter_existing', 'array', []) as $tableName) {
        $tableName = trim((string) $tableName);
        if ('' === $tableName) { continue; }
        $previews[] = [
            'mode' => 'refresh',
            'key' => $tableName,
            'tableName' => $tableName,
            'mappings' => $importer->analyze($tableName, $tableName),
        ];
    }
    // Mapping mode: show only the preview (and a way back).
    echo $importer->getMessage()->getAll();
    echo renderYformPreview($previews, $csrfToken);
    return;
}

if ('yform_import' === $func) {
    $importer = new YFormImporter($config, new Message());
    $posted = rex_request('mapping', 'array', []); // [tableKey][fieldIndex] => [name,type,label,params...]
    foreach (rex_request('yform_mode', 'array', []) as $key => $mode) {
        $mappings = buildMappingsFromPost($posted[$key] ?? []);
        if ('import' === $mode) {
            $importer->import($key, $mappings);
        } elseif ('refresh' === $mode) {
            $importer->refreshFields($key, $mappings);
        }
    }
    echo $importer->getMessage()->getAll();
}

// --- Render: choose tables to analyze --------------------------------------

$importer = new YFormImporter($config, new Message());
$newTables = $importer->detectCustomTables();
$existingTables = $importer->detectExistingYFormTables();

$checks = '';
if (count($newTables)) {
    $checks .= '<h4>' . rex_i18n::msg('yconverter_yform_new_tables') . '</h4>';
    foreach ($newTables as $t) {
        $checks .= sprintf(
            '<div class="checkbox"><label><input type="checkbox" name="yconverter_new[]" value="%s" checked> <code>%s</code> &rarr; <code>%s</code></label></div>',
            rex_escape($t), rex_escape($t), rex_escape(rex::getTable('yf_' . $t))
        );
    }
}
if (count($existingTables)) {
    $checks .= '<h4>' . rex_i18n::msg('yconverter_yform_existing_tables') . '</h4>';
    foreach ($existingTables as $t) {
        $checks .= sprintf(
            '<div class="checkbox"><label><input type="checkbox" name="yconverter_existing[]" value="%s"> <code>%s</code> <small class="text-muted">(%s)</small></label></div>',
            rex_escape($t['table_name']), rex_escape($t['table_name']), rex_escape($t['name'])
        );
    }
}

if ('' === $checks) {
    $body = '<p>' . rex_i18n::msg('yconverter_yform_no_custom_tables') . '</p>';
} else {
    $body = '<form action="' . rex_url::currentBackendPage() . '" method="post">'
        . '<input type="hidden" name="func" value="yform_analyze" />'
        . $csrfToken->getHiddenField()
        . '<p>' . rex_i18n::msg('yconverter_yform_custom_tables_info') . '</p>'
        . $checks
        . '<button class="btn btn-primary btn-lg" type="submit">' . rex_i18n::msg('yconverter_yform_analyze') . '</button>'
        . '</form>';
}

$fragment = new rex_fragment();
$fragment->setVar('title', rex_i18n::msg('yconverter_yform_custom_tables'), false);
$fragment->setVar('body', $body, false);
echo $fragment->parse('core/page/section.php');

// --- Helpers ---------------------------------------------------------------

function renderYformPreview(array $previews, rex_csrf_token $csrfToken)
{
    if (!count($previews)) {
        return rex_view::info(rex_i18n::msg('yconverter_yform_no_custom_tables'));
    }

    $allowed = FieldTypes::available();

    $out = '<form action="' . rex_url::currentBackendPage() . '" method="post">'
        . '<input type="hidden" name="func" value="yform_import" />'
        . $csrfToken->getHiddenField();

    foreach ($previews as $preview) {
        $key = $preview['key'];
        $modeLabel = 'import' === $preview['mode']
            ? '<span class="label label-success">' . rex_i18n::msg('yconverter_yform_mode_import') . '</span>'
            : '<span class="label label-warning">' . rex_i18n::msg('yconverter_yform_mode_refresh') . '</span>';

        $out .= '<input type="hidden" name="yform_mode[' . rex_escape($key) . ']" value="' . rex_escape($preview['mode']) . '" />';
        $rows = '';
        foreach ($preview['mappings'] as $i => $m) {
            // Keep an existing/unknown field type selectable so re-detection never silently loses it.
            $types = $allowed;
            if ('' !== $m->typeName && !in_array($m->typeName, $types, true)) {
                array_unshift($types, $m->typeName);
            }
            $select = '<select class="form-control" name="mapping[' . rex_escape($key) . '][' . $i . '][type]">';
            foreach ($types as $type) {
                $select .= '<option value="' . rex_escape($type) . '"' . ($type === $m->typeName ? ' selected' : '') . '>' . rex_escape($type) . '</option>';
            }
            $select .= '<option value="__remove__"' . ('__remove__' === $m->typeName ? ' selected' : '') . '>' . rex_i18n::msg('yconverter_yform_remove_column') . '</option>';
            $select .= '</select>';

            $paramsString = '';
            foreach ($m->params as $pName => $pVal) {
                $paramsString .= $pName . '=' . (string) $pVal . "\n";
            }

            $badgeClass = ['HIGH' => 'success', 'MEDIUM' => 'info', 'LOW' => 'default'][$m->confidence] ?? 'default';
            $colLabel = !empty($m->members['columns']) ? implode(', ', $m->members['columns']) : $m->name;

            $rows .= '<tr>'
                . '<td><code>' . rex_escape($colLabel) . '</code>'
                . '<input type="hidden" name="mapping[' . rex_escape($key) . '][' . $i . '][name]" value="' . rex_escape($m->name) . '" />'
                . '<input type="hidden" name="mapping[' . rex_escape($key) . '][' . $i . '][dbType]" value="' . rex_escape($m->dbType) . '" />'
                . '<input type="hidden" name="mapping[' . rex_escape($key) . '][' . $i . '][list_hidden]" value="' . (int) $m->listHidden . '" />'
                . '<input type="hidden" name="mapping[' . rex_escape($key) . '][' . $i . '][search]" value="' . (int) $m->search . '" />'
                . ($m->members ? '<input type="hidden" name="mapping[' . rex_escape($key) . '][' . $i . '][members]" value="' . rex_escape(json_encode($m->members)) . '" />' : '')
                . '</td>'
                . '<td>' . $select . '</td>'
                . '<td><input class="form-control" type="text" name="mapping[' . rex_escape($key) . '][' . $i . '][label]" value="' . rex_escape($m->label) . '" /></td>'
                . '<td><textarea class="form-control" rows="1" name="mapping[' . rex_escape($key) . '][' . $i . '][params]">' . rex_escape(trim($paramsString)) . '</textarea></td>'
                . '<td><span class="label label-' . $badgeClass . '">' . rex_escape($m->confidence) . '</span></td>'
                . '<td><small>' . rex_escape($m->reason) . '</small></td>'
                . '</tr>';
        }

        $warn = 'refresh' === $preview['mode'] ? rex_view::warning(rex_i18n::msg('yconverter_yform_refresh_warning')) : '';
        $table = '<table class="table table-striped"><thead><tr>'
            . '<th>' . rex_i18n::msg('yconverter_yform_col_columns') . '</th>'
            . '<th>' . rex_i18n::msg('yconverter_yform_col_type') . '</th>'
            . '<th>' . rex_i18n::msg('yconverter_yform_col_label') . '</th>'
            . '<th>' . rex_i18n::msg('yconverter_yform_col_params') . '</th>'
            . '<th>' . rex_i18n::msg('yconverter_yform_col_confidence') . '</th>'
            . '<th>' . rex_i18n::msg('yconverter_yform_col_reason') . '</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table>';

        $fragment = new rex_fragment();
        $fragment->setVar('title', $modeLabel . ' <code>' . rex_escape($preview['tableName']) . '</code>', false);
        $fragment->setVar('body', $warn . $table, false);
        $out .= $fragment->parse('core/page/section.php');
    }

    $out .= '<div style="margin: 15px 0 40px;">';
    $out .= '<button class="btn btn-primary btn-lg" type="submit">' . rex_i18n::msg('yconverter_yform_apply') . '</button>';
    $out .= ' <a class="btn btn-default btn-lg" href="' . rex_url::currentBackendPage() . '">' . rex_i18n::msg('yconverter_yform_cancel') . '</a>';
    $out .= '</div>';
    $out .= '</form>';

    return $out;
}

function buildMappingsFromPost(array $postedFields)
{
    $mappings = [];
    foreach ($postedFields as $row) {
        $params = [];
        if (!empty($row['params'])) {
            foreach (preg_split('/\r\n|\r|\n/', (string) $row['params']) as $line) {
                $line = trim($line);
                if ('' === $line || false === strpos($line, '=')) {
                    continue;
                }
                list($pName, $pVal) = explode('=', $line, 2);
                $params[trim($pName)] = trim($pVal);
            }
        }
        $opts = [
            'label' => isset($row['label']) ? (string) $row['label'] : '',
            'dbType' => isset($row['dbType']) ? (string) $row['dbType'] : '',
            'params' => $params,
            'source' => 'manual',
            'listHidden' => (int) (isset($row['list_hidden']) ? $row['list_hidden'] : 0),
            'search' => (int) (isset($row['search']) ? $row['search'] : 1),
        ];
        if (!empty($row['members'])) {
            $decoded = json_decode((string) $row['members'], true);
            if (is_array($decoded)) {
                $opts['members'] = $decoded;
            }
        }
        $mappings[] = new FieldMapping((string) $row['name'], (string) $row['type'], $opts);
    }
    return $mappings;
}
