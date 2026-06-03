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

$addon = rex_addon::get('yconverter');
$config = Config::read();
$csrfToken = rex_csrf_token::factory('system');

// --- Save (only this sub-page's fields; AI settings are preserved) -----------
if ('save_general' === rex_request('func', 'string')) {
    if (!$csrfToken->isValid()) {
        echo rex_view::error(rex_i18n::msg('csrf_token_invalid'));
    } else {
        $posted = rex_post('settings', [
            ['host', 'string'],
            ['login', 'string'],
            ['password', 'string'],
            ['name', 'string'],
            ['persistent', 'bool'],
            ['core_version', 'string'],
            ['table_prefix', 'string', 'rex_'],
            ['media_source_path', 'string'],
            ['media_source_url', 'string'],
        ], []);

        // Keep the stored DB password when the field is submitted blank.
        if ('' !== $posted['login'] && '' === $posted['password'] && '' !== (string) $config['db']['2']['password']) {
            $posted['password'] = $config['db']['2']['password'];
        }

        foreach (['host', 'login', 'password', 'name', 'persistent'] as $key) {
            $config['db']['2'][$key] = $posted[$key];
        }
        $config['core_version'] = $posted['core_version'];
        $config['table_prefix'] = $posted['table_prefix'];
        $config['media_source_path'] = $posted['media_source_path'];
        $config['media_source_url'] = $posted['media_source_url'];

        if (Config::write($config)) {
            echo rex_view::success($addon->i18n('settings_saved'));
        } else {
            echo rex_view::error($addon->i18n('settings_error', Config::file()));
        }
    }
}

// --- Render ------------------------------------------------------------------
$section = function ($title, array $formElements, array $checkboxElements = []) {
    $fragment = new rex_fragment();
    $fragment->setVar('elements', $formElements, false);
    $body = $fragment->parse('core/form/form.php');

    if ($checkboxElements) {
        $fragment = new rex_fragment();
        $fragment->setVar('elements', $checkboxElements, false);
        $body .= $fragment->parse('core/form/checkbox.php');
    }

    $fragment = new rex_fragment();
    $fragment->setVar('class', 'edit', false);
    $fragment->setVar('title', $title, false);
    $fragment->setVar('body', $body, false);
    return $fragment->parse('core/page/section.php');
};

$coreVersion = new rex_select();
$coreVersion->setName('settings[core_version]');
$coreVersion->setAttribute('class', 'form-control selectpicker');
$coreVersion->setSize(1);
$coreVersion->setSelected($config['core_version']);
$coreVersion->addArrayOptions([
    '2.7.4', '3.0.0', '3.1.0', '3.2.0',
    '4.0.0', '4.0.1', '4.1.0', '4.2.0', '4.2.1', '4.3.0', '4.3.1', '4.3.2', '4.3.3',
    '4.4.0', '4.4.1', '4.5.0', '4.5.1', '4.6.0', '4.6.1', '4.6.2', '4.7.0', '4.7.1', '4.7.2', '4.7.3',
], false);

// Section: source database (REDAXO 4)
$dbFields = [];
$dbFields[] = ['label' => '<label>'.$addon->i18n('core_version').'</label>', 'field' => $coreVersion->get()];
$dbFields[] = ['label' => '<label>'.$addon->i18n('table_prefix').'</label>', 'field' => '<input class="form-control" type="text" name="settings[table_prefix]" value="'.rex_escape($config['table_prefix']).'" />'];
$dbFields[] = ['label' => '<label>'.$addon->i18n('database_host').'</label>', 'field' => '<input class="form-control" type="text" name="settings[host]" value="'.rex_escape((string) $config['db']['2']['host']).'" />', 'note' => $addon->i18n('database_connection_notice')];
$dbFields[] = ['label' => '<label>'.$addon->i18n('database_user').'</label>', 'field' => '<input class="form-control" type="text" name="settings[login]" value="'.rex_escape((string) $config['db']['2']['login']).'" />'];
$dbFields[] = ['label' => '<label>'.$addon->i18n('database_password').'</label>', 'field' => '<input class="form-control" type="password" name="settings[password]" value="" placeholder="'.rex_escape($config['db']['2']['password'] ? $addon->i18n('database_password_exists') : '').'" />'];
$dbFields[] = ['label' => '<label>'.$addon->i18n('database_name').'</label>', 'field' => '<input class="form-control" type="text" name="settings[name]" value="'.rex_escape((string) $config['db']['2']['name']).'" />'];

$dbCheckbox = [[
    'reverse' => true,
    'label' => '<label>'.$addon->i18n('database_persistent').'</label>',
    'field' => '<input type="checkbox" name="settings[persistent]" value="1" '.($config['db']['2']['persistent'] ? 'checked="checked" ' : '').'/>',
]];

// Section: media
$mediaFields = [];
$mediaFields[] = ['label' => '<label>'.$addon->i18n('media_source_url').'</label>', 'field' => '<input class="form-control" type="text" name="settings[media_source_url]" value="'.rex_escape((string) $config['media_source_url']).'" placeholder="https://www.example.com" />', 'note' => $addon->i18n('media_source_url_notice')];
$mediaFields[] = ['label' => '<label>'.$addon->i18n('media_source_path').'</label>', 'field' => '<input class="form-control" type="text" name="settings[media_source_path]" value="'.rex_escape((string) $config['media_source_path']).'" />', 'note' => $addon->i18n('media_source_path_notice')];

$content = $section($addon->i18n('settings_section_database'), $dbFields, $dbCheckbox);
$content .= $section($addon->i18n('settings_section_media'), $mediaFields);
$content .= '<button class="btn btn-save" type="submit" name="sendit"'.rex::getAccesskey(rex_i18n::msg('system_update'), 'save').'>'.rex_i18n::msg('system_update').'</button>';

echo '
<form action="'.rex_url::currentBackendPage().'" method="post">
    <input type="hidden" name="func" value="save_general" />
    '.$csrfToken->getHiddenField().'
    '.$content.'
</form>';
