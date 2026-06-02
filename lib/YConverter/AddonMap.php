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

use YConverter\Package\Core;
use YConverter\Package\Cronjob;
use YConverter\Package\Sprog;
use YConverter\Package\YForm;

/**
 * Central, editable map of REDAXO 4 -> REDAXO 5 conversions.
 *
 * This is intentionally a PHP class (not config.yml): the code-rewrite values are
 * ordered regular-expression pairs with backreferences, which YAML would mangle.
 * Extend the arrays below to cover more addons. The addon mapping is best-effort —
 * there are too many third-party addons to cover them all automatically.
 */
class AddonMap
{
    /**
     * Migratable subsystems: UI/rex_config key => Package class.
     * Drives both the backend dispatch and the CLI command, so routing is no longer
     * hardcoded in a page-level switch. Add a Package subclass here to support it.
     *
     * @return array<string, class-string<\YConverter\Package\Package>>
     */
    public static function packageRouting()
    {
        return [
            'core' => Core::class,
            'cronjob' => Cronjob::class,
            'sprog' => Sprog::class,
            'yform' => YForm::class,
        ];
    }

    /**
     * REDAXO 4 source tables (base name, i.e. without the source prefix) that belong to
     * the core or to a well-known addon. Everything else in the source DB is treated as a
     * project-specific (custom) table and may be offered for conversion to YForm.
     * Best-effort — extend as needed.
     *
     * @return string[]
     */
    public static function knownSourceTables()
    {
        return [
            // core (REDAXO 4 names)
            'action', 'article', 'article_slice', 'clang', 'file', 'file_category',
            'module', 'module_action', 'template', 'user',
            // metainfo (REDAXO 4)
            '62_params', '62_type',
            // image_resize / media_manager (REDAXO 4)
            '679_types', '679_type_effects',
            // cronjob
            '630_cronjobs',
            // sprog
            'b_1_opf_lang',
            // xform / yform
            'xform_table', 'xform_field', 'xform_email_template',
            // other well-known addons
            'tinymce_profiles', 'url_control_generate', 'url_control_manager', 'redirects',
            // REDAXO 5 names the staging tables are renamed to during "update", so they are
            // not offered as custom tables once a migration has run.
            'media', 'media_category', 'media_manager_type', 'media_manager_type_effect',
            'metainfo_field', 'metainfo_type', 'cronjob', 'sprog_wildcard',
            'yform_table', 'yform_field', 'yform_email_template',
        ];
    }

    public static function isKnownTable($baseName)
    {
        return \in_array($baseName, self::knownSourceTables(), true);
    }

    /**
     * REDAXO 4 source table (base name, i.e. without the source prefix) => the REDAXO 5
     * addon that should be installed/activated to receive its data. Used by
     * InstallChecklist to produce an advisory (never auto-installing) list. Best-effort.
     *
     * @return array<string, string>
     */
    public static function r4ToR5Addons()
    {
        return [
            '62_params' => 'metainfo',
            '62_type' => 'metainfo',
            '679_types' => 'media_manager',
            '679_type_effects' => 'media_manager',
            'b_1_opf_lang' => 'sprog',
            '630_cronjobs' => 'cronjob',
            'xform_table' => 'yform',
            'xform_field' => 'yform',
            'tinymce_profiles' => 'tinymce',
            'url_control_manager' => 'url',
            'url_control_generate' => 'url',
            'redirects' => 'yrewrite',
            'com_user' => 'ycom',
        ];
    }

    /**
     * Ordered code-reference rewrites applied to module/template/action contents
     * (R4 idioms -> R5 API). Append a new group at the end to cover your own addons.
     *
     * @return array<int, array{regex?: string, replaces: array}>
     */
    public static function replaces(Config $config)
    {
        return [
            [
                // $REX
                'regex' => '\$REX\s*\[[\'\"]$$SEARCH$$[\'\"]\]([^\[])',
                'replaces' => [
                    ['ARTICLE_ID' => 'rex_article::getCurrentId()$1'],
                    ['CLANG' => 'rex_clang::getAll()$1'],
                    ['CUR_CLANG' => 'rex_clang::getCurrentId()$1'],
                    ['ERROR_EMAIL' => 'rex::getErrorEmail()$1'],
                    ['FRONTEND_FILE' => 'rex_path::frontendController()$1'],
                    ['FRONTEND_PATH' => 'rex_path::frontend()$1'],
                    ['HTDOCS_PATH' => 'rex_path::frontend()$1'],
                    ['INCLUDE_PATH' => 'rex_path::src() . \'/addons/\'$1'],
                    ['MEDIAFOLDER' => 'rex_path::media()$1'],
                    ['NOTFOUND_ARTICLE_ID' => 'rex_article::getNotFoundArticleId()$1'],
                    ['REDAXO' => 'rex::isBackend()$1'],
                    ['SERVER' => 'rex::getServer()$1'],
                    ['SERVERNAME' => 'rex::getServerName()$1'],
                    ['START_ARTICLE_ID' => 'rex_article::getSiteStartArticleId()$1'],
                    ['TABLE_PREFIX' => 'rex::getTablePrefix()$1'],
                    ['USER' => 'rex::getUser()$1'],
                ],
            ], [
                // OOF Spezial
                'replaces' => [
                    ['new\s*rex_article' => 'new rex_article_content'],
                    ['new\s*article' => 'new rex_article_content'],
                    ['OOArticle\s*::\s*getArticleById\(' => 'rex_article::get('],
                    ['OOCategory\s*::\s*getCategoryById\(' => 'rex_category::get('],
                    ['OOCategory\s*::\s*getChildrenById\((.*?),\s*(.*?)\)' => 'rex_category::get($1)->getChildren($2)'],
                    ['OOMedia\s*::\s*getMediaByFilename\(' => 'rex_media::get('],
                    ['OOMedia\s*::\s*getMediaByName\(' => 'rex_media::get('],
                    ['OOMediaCategory\s*::\s*getCategoryById\(' => 'rex_media_category::get('],
                    ['OOAddon\s*::\s*isActivated\((.*?)\)' => 'rex_addon::get($1)->isActivated()'],
                    ['OOAddon\s*::\s*isAvailable\((.*?)\)' => 'rex_addon::get($1)->isAvailable()'],
                    ['OOAddon\s*::\s*isInstalled\((.*?)\)' => 'rex_addon::get($1)->isInstalled()'],
                    ['OOAddon\s*::\s*getProperty\((.*?),\s*(.*?)\)' => 'rex_addon::get($1)->getProperty($2)'],
                    ['OOPlugin\s*::\s*isActivated\((.*?),\s*(.*?)\)' => 'rex_plugin::get($1, $2)->isActivated()'],
                    ['OOPlugin\s*::\s*isAvailable\((.*?),\s*(.*?)\)' => 'rex_plugin::get($1, $2)->isAvailable()'],
                    ['OOPlugin\s*::\s*isInstalled\((.*?),\s*(.*?)\)' => 'rex_plugin::get($1, $2)->isInstalled()'],
                    ['OOPlugin\s*::\s*getProperty\((.*?),\s*(.*?),\s*(.*?)\)' => 'rex_plugin::get($1, $2)->getProperty($3)'],
                ],
            ], [
                // OOF
                'regex' => '$$SEARCH$$\s*::\s*([a-zA-Z]+)\((.*?)\)',
                'replaces' => [
                    ['OOArticle' => 'rex_article::$1($2)'],
                    ['OOCategory' => 'rex_category::$1($2)'],
                    ['OOMedia' => 'rex_media::$1($2)'],
                    ['OOMediaCategory' => 'rex_media_category::$1($2)'],
                    ['OOArticleSlice' => 'rex_article_slice::$1($2)'],
                ],
            ], [
                // OO isValid
                'regex' => '$$SEARCH$$\s*::\s*isValid\((.*?)\)',
                'replaces' => [
                    ['OOArticle' => '$1 instanceof rex_article'],
                    ['OOCategory' => '$1 instanceof rex_category'],
                    ['OOMedia' => '$1 instanceof rex_media'],
                    ['OOMediaCategory' => '$1 instanceof rex_media_category'],
                ],
            ], [
                // REX_
                'replaces' => [
                    ['REX_EXTENSION_EARLY' => 'rex_extension::EARLY'],
                    ['REX_EXTENSION_LATE' => 'rex_extension::LATE'],
                    ['REX_FILE\[([1-9]+)\]' => 'REX_MEDIA[id=$1]'],
                    ['REX_HTML_VALUE\[([0-9]+)\]' => 'REX_VALUE[id=$1 output=html]'],
                    ['REX_HTML_VALUE\[id=[\"\']([0-9]+)(.*?)\]' => 'REX_VALUE[id=$1 output=html $2]'],
                    ['REX_IS_VALUE\[([1-9]+)\]' => 'REX_VALUE[id=$1 isset=1]'],
                    ['REX_LINK_BUTTON\[(id=)?([1-9]|10)(.*?)\]' => 'REX_LINK[id=$2 widget=1$3]'],
                    ['REX_LINK_ID\[(id=)?([1-9]|10)(.*?)\]' => 'REX_LINK[id=$2 output=id$3]'],
                    ['REX_LINK\[(id=)?([1-9]|10)(.*?)\]' => 'REX_LINK[id=$2 output=url$3]'],
                    ['REX_LINKLIST_BUTTON\[(id=)?([1-9]|10)(.*?)\]' => 'REX_LINKLIST[id=$2 widget=1$3]'],
                    ['REX_MEDIA_BUTTON\[(id=)?([1-9]|10)(.*?)\]' => 'REX_MEDIA[id=$2 widget=1$3]'],
                    ['REX_MEDIALIST_BUTTON\[(id=)?([1-9]|10)(.*?)\]' => 'REX_MEDIALIST[id=$2 widget=1$3]'],
                    // muss hier stehen
                    ['INPUT_PHP' => 'REX_INPUT_VALUE['.$config->getNewPhpValueField().']'],
                    ['REX_PHP' => 'REX_VALUE[id='.$config->getNewPhpValueField().' output=php]'],
                    ['INPUT_HTML' => 'REX_INPUT_VALUE['.$config->getNewHtmlValueField().']'],
                    ['REX_HTML' => 'REX_VALUE[id='.$config->getNewHtmlValueField().' output=html]'],
                    ['([^_])VALUE\[(id=)?([1-9]+[0-9]*)\]' => '$1REX_INPUT_VALUE[$3]'],
                ],
            ], [
                // Extension Points
                'replaces' => [
                    ['ALL_GENERATED' => 'CACHE_DELETED'],
                    ['ADDONS_INCLUDED' => 'PACKAGES_INCLUDED'],
                    ['OUTPUT_FILTER_CACHE' => 'RESPONSE_SHUTDOWN'],
                    ['OOMEDIA_IS_IN_USE' => 'MEDIA_IS_IN_USE'],
                ],
            ], [
                // Core Rest
                'replaces' => [
                    ['\$I18N\-\>msg\(' => 'rex_i18n::msg('],
                    ['(\/?)files\/' => '$1media/'],
                    ['\-\>countFiles\(' => '->countMedia('],
                    ['getDescription\(' => 'getValue(\'description\''],
                    ['\-\>getFiles\(' => '->getMedia('],
                    ['\-\>hasFiles\(' => '->hasMedia('],
                    ['isStartPage\(' => 'isStartArticle('],
                    ['rex_absPath\(' => 'rex_path::absolute('],
                    ['rex_copyDir\(' => 'rex_dir::copy('],
                    ['rex_createDir\(' => 'rex_dir::create('],
                    ['rex_deleteAll\(' => 'rex_deleteCache('],
                    ['rex_deleteDir\(' => 'rex_dir::delete('],
                    ['rex_deleteFiles\(' => 'rex_dir::deleteFiles('],
                    ['rex_generateAll\(' => 'rex_deleteCache('],
                    ['rex_hasBackendSession\(' => 'rex_backend_login::hasSession('],
                    ['rex_highlight_string\(' => 'rex_string::highlight('],
                    ['rex_highlight_file\(' => 'rex_string::highlight('],
                    ['rex_img_type' => 'rex_media_type'],
                    ['rex_img_file' => 'rex_media_file'],
                    ['rex_info\(' => 'rex_view::info('],
                    ['rex_install_dump\(' => 'rex_sql_util::importDump('],
                    ['rex_organize_priorities\(' => 'rex_sql_util::organizePriorities('],
                    ['rex_parse_article_name\(' => 'rex_string::normalize('],
                    ['rex_register_extension\(' => 'rex_extension::register('],
                    ['rex_register_extension_point\(' => 'rex_extension::registerPoint('],
                    ['rex_send_article\(' => 'rex_response::sendArticle('],
                    ['rex_send_content\(' => 'rex_response::sendContent('],
                    ['rex_send_file\(' => 'rex_response::sendFile('],
                    ['rex_send_resource\(' => 'rex_response::sendResource('],
                    ['rex_split_string' => 'rex_string::split()'],
                    ['new\s*rex_sql' => 'rex_sql::factory'],
                    ['rex_title\(' => 'rex_view::title('],
                    ['rex_translate\(' => 'rex_i18n::translate('],
                    ['rex_warning\(' => 'rex_view::error('],
                    ['instanceof\s*OOArticle' => 'instanceof rex_article'],
                    ['instanceof\s*OOCategory' => 'instanceof rex_category'],
                    ['instanceof\s*OOMedia' => 'instanceof rex_media'],
                    ['instanceof\s*OOMediaCategory' => 'instanceof rex_media_category'],
                ],
            ], [
                // Image Manager
                'replaces' => [
                    ['rex_image_manager::getImageCache\((.*?)\s*,\s*(.*?)\)' => 'rex_media_manager::create($2, $1)->getMedia()'],
                ],
            ], [
                // Textile
                'replaces' => [
                    ['\$.*?=\s*htmlspecialchars_decode\((\'|")REX_VALUE\[(id=)?([1-9]+[0-9]*)\]\1(,\s*ENT_QUOTES)?\);\v*.*?\v*(.*?)rex_a79_textile\s*\(\s*(.*?)(,.*?)\)' => '$5markitup::parseOutput(\'textile\', \'REX_VALUE[id=$3 output="html"]\')'],
                    ['\$.*?=\s*htmlspecialchars_decode\((.*?)(,\s*ENT_QUOTES)?\);\v*.*?\v*(.*?)rex_a79_textile\s*\(\s*(.*?)(,.*?)\)' => '$3markitup::parseOutput(\'textile\', $1)'],
                    ['rex_a79_textile\s*\(\s*(.*?)\s*(,.*?)\)' => 'markitup::parseOutput(\'textile\', $1)'],
                ],
            ],[
                // XForm
                'replaces' => [
                    ['db2email' => 'tpl2email'],
                    ['notEmpty' => 'empty'],
                ],
            ], [
                // SEO42
                'replaces' => [
                    ['seo42\s*::\s*getMediaFile\(' => 'rex_url::media('],
                    ['seo42\s*::\s*getLangCode\((.*?)\)' => 'rex_clang::getCurrent()->getCode()'],
                    ['seo42\s*::\s*getBaseUrl\((.*?)\)' => 'rex::getServer()'],
                ],
            ], [
                // Community Addon -> YCom
                'replaces' => [
                    ['checkperm\(' => 'checkPerm('],
                    ['rex_addon::get\((["\']{1})community\1\)->isActivated\(\)' => 'rex_addon::get(\'ycom\')->isActivated()'],
                    ['rex_addon::get\((["\']{1})community\1\)->isAvailable\(\)' => 'rex_addon::get(\'ycom\')->isAvailable()'],
                    ['rex_addon::get\((["\']{1})community\1\)->isInstalled\(\)' => 'rex_addon::get(\'ycom\')->isInstalled()'],
                    ['rex_com_auth\s*::' => 'rex_ycom_auth::'],
                    ['com_auth_db' => 'ycom_auth_db'],
                    ['com_auth_form_' => 'ycom_auth_form_'],
                    ['com_auth_load_user' => 'ycom_auth_load_user'],
                    ['com_auth_password_hash\|.*' => ''],
                    ['password\|.*?\|' => 'ycom_auth_password|$1|'],
                ],
            ],
        ];
    }

    /**
     * Code occurrences that cannot be auto-rewritten and should be flagged for manual review.
     *
     * @return array<int, array{regex?: string, matches: string[]}>
     */
    public static function outdatedCode()
    {
        return [
            [
                // $REX
                'regex' => '(\$REX\s*\[[\'\"]$$SEARCH$$[\'\"]\])',
                'matches' => [
                    //'MOD_REWRITE',
                    '.*?',
                ],
            ], [
                'matches' => [
                    'getCategoryByName\(',
                    'rex_addslashes',
                    'rex_call_func',
                    'rex_check_callable',
                    'rex_create_lang',
                    'rex_getAttributes',
                    'rex_lang_is_utf8',
                    'rex_replace_dynamic_contents',
                    'rex_setAttributes',
                    'rex_tabindex',
                ],
            ],
        ];
    }
}
