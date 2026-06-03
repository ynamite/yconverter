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

// --- Save (only the AI fields; general settings are preserved) ---------------
if ('save_ai' === rex_request('func', 'string')) {
    if (!$csrfToken->isValid()) {
        echo rex_view::error(rex_i18n::msg('csrf_token_invalid'));
    } else {
        $posted = rex_post('settings', [
            ['ai_provider', 'string', 'none'],
            ['ai_api_key', 'string'],
            ['ai_model', 'string'],
            ['ai_send_samples', 'bool'],
        ], []);

        // Keep the stored API key when the field is submitted blank.
        if ('' === $posted['ai_api_key'] && '' !== (string) $config['ai_api_key']) {
            $posted['ai_api_key'] = $config['ai_api_key'];
        }

        $config['ai_provider'] = $posted['ai_provider'];
        $config['ai_api_key'] = $posted['ai_api_key'];
        $config['ai_model'] = $posted['ai_model'];
        $config['ai_send_samples'] = $posted['ai_send_samples'];

        if (Config::write($config)) {
            echo rex_view::success($addon->i18n('settings_saved'));
        } else {
            echo rex_view::error($addon->i18n('settings_error', Config::file()));
        }
    }
}

// --- Render ------------------------------------------------------------------
echo rex_view::info($addon->i18n('ai_provider_notice'));

$aiProvider = new rex_select();
$aiProvider->setName('settings[ai_provider]');
$aiProvider->setAttribute('class', 'form-control selectpicker');
$aiProvider->setSize(1);
$aiProvider->setSelected($config['ai_provider']);
$aiProvider->addOption($addon->i18n('ai_provider_none'), 'none');
$aiProvider->addOption('OpenAI', 'openai');
$aiProvider->addOption('Anthropic', 'anthropic');

$formElements = [];
$formElements[] = ['label' => '<label>'.$addon->i18n('ai_provider').'</label>', 'field' => $aiProvider->get()];
$formElements[] = ['label' => '<label>'.$addon->i18n('ai_api_key').'</label>', 'field' => '<input class="form-control" type="password" name="settings[ai_api_key]" value="" placeholder="'.rex_escape(!empty($config['ai_api_key']) ? $addon->i18n('ai_api_key_exists') : '').'" />'];
$formElements[] = ['label' => '<label>'.$addon->i18n('ai_model').'</label>', 'field' => '<input class="form-control" type="text" name="settings[ai_model]" value="'.rex_escape((string) $config['ai_model']).'" placeholder="'.rex_escape($addon->i18n('ai_model_placeholder')).'" />'];

$fragment = new rex_fragment();
$fragment->setVar('elements', $formElements, false);
$body = $fragment->parse('core/form/form.php');

$checkboxElements = [[
    'reverse' => true,
    'label' => '<label>'.$addon->i18n('ai_send_samples').'</label>',
    'field' => '<input type="checkbox" name="settings[ai_send_samples]" value="1" '.($config['ai_send_samples'] ? 'checked="checked" ' : '').'/>',
]];
$fragment = new rex_fragment();
$fragment->setVar('elements', $checkboxElements, false);
$body .= $fragment->parse('core/form/checkbox.php');

$fragment = new rex_fragment();
$fragment->setVar('class', 'edit', false);
$fragment->setVar('title', $addon->i18n('ai_heading'), false);
$fragment->setVar('body', $body, false);
$content = $fragment->parse('core/page/section.php');
$content .= '<button class="btn btn-save" type="submit" name="sendit"'.rex::getAccesskey(rex_i18n::msg('system_update'), 'save').'>'.rex_i18n::msg('system_update').'</button>';

echo '
<form action="'.rex_url::currentBackendPage().'" method="post">
    <input type="hidden" name="func" value="save_ai" />
    '.$csrfToken->getHiddenField().'
    '.$content.'
</form>';
